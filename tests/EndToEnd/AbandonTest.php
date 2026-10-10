<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\EndToEnd;

use NeuronCore\Cloud\Tests\Support\EndToEnd\Deployment;
use NeuronCore\Cloud\Tests\Support\EndToEnd\EndToEndTestCase;
use NeuronCore\Cloud\Tests\Support\EndToEnd\SimulatedPlatform;
use NeuronCore\Cloud\Tests\Support\EndToEnd\WireLog;
use NeuronCore\Cloud\Tests\Support\ExampleApp\AwaitPaymentNode;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\FaultyStore;
use NeuronCore\Cloud\Tests\Support\ExampleApp\OrderWorkflow;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Shop;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;

use function array_column;

/**
 * An operator asks the platform simulator to abandon an order of the shop of
 * example (a), over real HTTP: rule 5 and the abandon answers of rule 7 of
 * appendix B of the design, against the real endpoint.
 */
class AbandonTest extends EndToEndTestCase
{
    protected const ORDER = 'A2001';

    protected Deployment $deployment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deployment = $this->forThisTest(new Deployment());
        self::assertSame(0, $this->deployment->shop->sync()['exitCode']);
    }

    public function test_an_abandon_frees_the_workflow_id_of_a_run_that_waits(): void
    {
        $this->startWorkers();
        $runId = $this->theOrderWaitsForItsPayment();

        $this->platform()->abandon($runId);
        $abandoned = $this->platform()->await('the run is abandoned', fn (): array|false => $this->closed($runId));

        self::assertSame('abandoned', $abandoned['state']);
        self::assertNull($this->deployment->shop->runOf(self::workflowId()));
        self::assertSame([1], array_column(array_column($this->platform()->wire()->of(WireLog::ABANDON), 'fields'), 'attempt'));
    }

    public function test_a_start_abandoned_while_it_waits_in_the_queue_never_runs(): void
    {
        $runId = $this->platform()->fire(Shop::DEFINITION, self::workflowId())['runId'];
        $this->platform()->tick();

        $this->platform()->abandon($runId);
        $abandoned = $this->platform()->await('the start is abandoned', fn (): array|false => $this->closed($runId));
        $this->startWorkers();
        $report = $this->platform()->await('a worker met the start', fn (): array|false => $this->platform()->wire()->of(WireLog::REPORT)[0]['fields'] ?? false);

        self::assertSame('abandoned', $abandoned['state']);
        self::assertSame(['rejected', 'already_claimed'], [$report['outcome'], $report['code']]);
        self::assertSame(0, $this->deployment->shop->nodeRuns->timesEntered(AwaitPaymentNode::class, self::ORDER));
        self::assertSame('abandoned', $this->platform()->run($runId)['state']);
    }

    /**
     * The case appendix A of the design names and cannot tell apart: the abandon meets a start that a worker
     * has claimed and whose run is about to appear. The endpoint answers 409 with no run, the platform
     * inspects, sees none and closes the run as "ended, outcome unknown". The run then appears.
     */
    public function test_a_run_that_appears_after_its_abandon_was_closed_as_unknown_is_open_again_with_its_timer(): void
    {
        // The first write of the worker is its claim. It stands before Neuron's first write of the run until the
        // platform has settled the abandon.
        $this->deployment->shop->faults->plan(FaultyStore::WRITE, Faults::PASS, Faults::HOLD);
        $this->startWorkers();
        $runId = $this->platform()->fire(Shop::DEFINITION, self::workflowId())['runId'];
        $this->platform()->await(
            'the worker claimed the start and stands before the first write of the run',
            fn (): bool => $this->deployment->shop->faults->pending(FaultyStore::WRITE) === [],
        );

        $this->platform()->abandon($runId);
        $closed = $this->platform()->await('the abandon is settled', fn (): array|false => $this->closed($runId));
        $this->deployment->shop->faults->release(FaultyStore::WRITE);
        $open = $this->platform()->await('the run appeared', fn (): array|false => $this->platform()->run($runId)['run'] === null ? false : $this->platform()->run($runId));

        self::assertSame([409, 'stale_run', null], $this->answerToTheAbandon());
        self::assertSame('ended_unknown', $closed['state']);
        self::assertSame(['open', 'suspended'], [$open['state'], $open['run']['status']]);
        self::assertSame([null, ['wake']], [$open['abandon'], array_column($open['timers'], 'action')], 'The request of the operator is gone.');
        self::assertSame($this->deployment->shop->runOf(self::workflowId()), $open['run']);
    }

    protected function platform(): SimulatedPlatform
    {
        return $this->deployment->platform;
    }

    protected function startWorkers(): void
    {
        $this->deployment->shop->startWorker();
        $this->deployment->shop->startWorker(SpoolDispatcher::NOTICES);
    }

    /**
     * @return string the run ID of the order the platform started
     */
    protected function theOrderWaitsForItsPayment(): string
    {
        $runId = $this->platform()->fire(Shop::DEFINITION, self::workflowId())['runId'];
        $this->platform()->await('the order waits for its payment', fn (): bool => ($this->platform()->run($runId)['run']['status'] ?? null) === 'suspended');

        return $runId;
    }

    /**
     * @return array<string, mixed>|false what the platform holds for the run once it is closed
     */
    protected function closed(string $runId): array|false
    {
        $held = $this->platform()->run($runId);

        return $held['state'] === 'open' ? false : $held;
    }

    /**
     * @return array{int, string|null, mixed} the status, the code and the run of the answer to the one abandon
     */
    protected function answerToTheAbandon(): array
    {
        [$abandon] = $this->platform()->wire()->of(WireLog::ABANDON);

        return [$abandon['status'], $abandon['answer']['code'] ?? null, $abandon['answer']['run'] ?? null];
    }

    protected static function workflowId(): string
    {
        return OrderWorkflow::workflowIdOf(self::ORDER);
    }
}
