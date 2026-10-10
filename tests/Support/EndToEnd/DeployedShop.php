<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\EndToEnd;

use NeuronAI\Workflow\Interrupt\SleepUntilRequest;
use NeuronAI\Workflow\Interrupt\WaitForEventRequest;
use NeuronAI\Workflow\Persistence\DatabasePersistence;
use NeuronAI\Workflow\WorkflowEngine;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Faults;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Home;
use NeuronCore\Cloud\Tests\Support\ExampleApp\NodeRuns;
use NeuronCore\Cloud\Tests\Support\ExampleApp\Spool;
use NeuronCore\Cloud\Tests\Support\ExampleApp\SpoolDispatcher;
use RuntimeException;

use function base64_decode;
use function count;
use function implode;
use function json_decode;
use function mkdir;
use function parse_url;
use function usleep;

use const PHP_BINARY;
use const PHP_URL_PORT;

/**
 * Example (a) of section 5 of the design, deployed: the shop of ExampleApp
 * with its web server on a free port and as many worker processes as a test
 * starts, all on one home directory. A test drives it over HTTP, as a browser
 * and a payment provider would, and reads its store and its queue.
 */
final class DeployedShop
{
    /** The public address the sync publishes. Nothing listens there: the simulator is given a tunnel to the web server. */
    public const PUBLIC_URL = 'https://shop.example.com';

    protected const APPLICATION = 'ExampleApp';

    /** Microseconds, where nothing tells when the reset of a connection has arrived. */
    protected const A_RESET_ARRIVES_WITHIN = 300_000;

    public readonly Home $home;

    /** How a test plans the misbehaviour of the shop. */
    public readonly Faults $faults;

    /** How many times each node body ran, order by order. */
    public readonly NodeRuns $nodeRuns;

    protected ?Server $web = null;

    /** bin/migrate.php, which the constructor started: null once it was seen to end. */
    protected ?Process $migration;

    /** The port of the web server, once it has been started. */
    protected ?int $webPort = null;

    /** @var array<string, Process> every process of the shop that was started, by the name of its log */
    protected array $processes = [];

    /** @var array<string, string> the environment every process of the shop gets */
    protected array $environment;

    /**
     * The migration of the shop is started and not waited for: the first serve() does that, while the web
     * server comes up.
     *
     * @param string $directory an existing directory, for the home of the shop and the logs of its processes
     * @param array<string, string> $environment what the bootstrap of the shop reads, beside its keys
     */
    public function __construct(protected string $directory, SimulatedPlatform $platform, array $environment = [])
    {
        mkdir("{$directory}/home");
        $this->home = new Home("{$directory}/home");
        $this->faults = new Faults($this->home->faults());
        $this->nodeRuns = new NodeRuns($this->home->executions());

        $this->environment = $environment + [
            'SHOP_HOME' => $this->home->directory,
            'SHOP_URL' => self::PUBLIC_URL,
            'NEURON_CLOUD_API_KEY' => SimulatedPlatform::API_KEY,
            'NEURON_CLOUD_SIGNING_KEY' => $platform->signingKey,
            'NEURON_CLOUD_PLATFORM_URL' => $platform->url(),
        ];

        $this->migration = $this->spawn('migrate', [], []);
    }

    /**
     * Start the web server of the shop: its endpoint, its checkout and its payment webhook. Started again
     * after stopServing(), it listens on the port it had.
     *
     * @param array<string, string> $environment what this web server sets differently
     * @return string the local address it listens on
     */
    public function serve(array $environment = []): string
    {
        $this->web = new Server(['-t', $this->script('public')], $environment + $this->environment, "{$this->directory}/web.log", $this->webPort);
        $this->webPort = (int) parse_url($this->web->url, PHP_URL_PORT);

        if ($this->migration instanceof Process) {
            $this->ended($this->migration, 'migrate');
            $this->migration = null;
        }

        $this->web->awaitListening();

        return $this->web->url;
    }

    /**
     * @return string the local address of the web server, for a test that calls the endpoint itself
     */
    public function url(): string
    {
        return (string) $this->web?->url;
    }

    /**
     * The web server wrote to a browser that had closed its connection. The reset that answers such a write
     * makes the next one fail: wait until it has arrived. Where the system does not list its sockets, a moment
     * is waited in its place.
     */
    public function awaitTheResetOfItsConnection(): void
    {
        $web = $this->web ?? throw new RuntimeException('The web server of the shop is not running.');

        if ($web->holdsAConnection() === null) {
            usleep(self::A_RESET_ARRIVES_WITHIN);

            return;
        }

        Wait::until('the web server was told that its browser has left', static fn (): bool => $web->holdsAConnection() === false);
    }

    /**
     * The web tier is down: nothing answers the platform, a browser or a payment provider.
     */
    public function stopServing(): void
    {
        $this->web?->stop();
        $this->web = null;
    }

    /**
     * bin/sync.php, the last step of a deploy.
     *
     * @param array<string, string> $environment what this deploy sets differently
     * @return array{exitCode: int|null, printed: string} how the script ended and what it wrote
     */
    public function sync(array $environment = []): array
    {
        $sync = $this->run('sync', [], $environment);

        return ['exitCode' => $sync->exitCode(), 'printed' => $sync->log()];
    }

    /**
     * bin/worker.php in a process of its own, until the test stops it.
     *
     * @param string $list the list it works on: the one of the segments, or the one of the notices
     * @param array<string, string> $environment what this worker sets differently
     */
    public function startWorker(string $list = SpoolDispatcher::SEGMENTS, array $environment = []): Process
    {
        return $this->spawn('worker', [$list], $environment);
    }

    /**
     * The cron of the example: the "later" lists go back to their queues.
     */
    public function retryLater(): void
    {
        $this->run('retry-later');
    }

    /**
     * A customer checks out.
     *
     * @param bool $inPlace whether the request runs the first segment itself, where it otherwise queues the start
     * @return array{status: int, body: array<string, mixed>}
     */
    public function checkout(string $orderId, string $note, bool $inPlace = false): array
    {
        $answer = Http::send('POST', "{$this->url()}/checkout.php", ['orderId' => $orderId, 'note' => $note, 'inPlace' => $inPlace]);

        return ['status' => $answer['status'], 'body' => (array) json_decode($answer['body'], true)];
    }

    /**
     * The payment provider calls the webhook.
     *
     * @return int the status of the answer: 202 when the payment was queued, 409 when the order is not waiting for one
     */
    public function pay(string $orderId, float $amount, string $reference): int
    {
        return Http::send('POST', "{$this->url()}/payment-webhook.php", ['orderId' => $orderId, 'amount' => $amount, 'reference' => $reference])['status'];
    }

    /**
     * A customer checks out on the page that shows the order as it is taken.
     *
     * @param string|null $leavesAt the step shown when the customer closes the page; null: the page stays open to the end
     * @return array{status: int, frames: string[]} the status of the answer and the steps the page showed
     */
    public function checkoutWatched(string $orderId, string $note, ?string $leavesAt = null): array
    {
        return Browser::watch("{$this->url()}/checkout-stream.php", ['orderId' => $orderId, 'note' => $note], $leavesAt);
    }

    /**
     * The back office approves or declines the payment of an order by hand.
     *
     * @return int the status of the answer: 202 when the decision was queued, 400 when no order waits for one,
     *             409 when the order waits for something else
     */
    public function reviewPayment(string $orderId, bool $approved): int
    {
        return Http::send('POST', "{$this->url()}/review-payment.php", ['orderId' => $orderId, 'approved' => $approved, 'reviewer' => 'back office'])['status'];
    }

    /**
     * The back office has an order that failed tried again now.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function recover(string $orderId): array
    {
        $answer = Http::send('POST', "{$this->url()}/recover-order.php", ['orderId' => $orderId]);

        return ['status' => $answer['status'], 'body' => (array) json_decode($answer['body'], true)];
    }

    /**
     * A person replays what a list kept aside as failed, once its cause is fixed.
     */
    public function replayFailed(string $list): void
    {
        $this->queue()->move("{$list}:failed", $list);
    }

    /**
     * The truth: the run holding a workflow ID in the store of the shop, in the shape of the run object of
     * the protocol, read with Neuron alone.
     *
     * @return array<string, mixed>|null
     */
    public function runOf(string $workflowId): ?array
    {
        $run = (new WorkflowEngine(new DatabasePersistence($this->home->store())))->inspect($workflowId);

        return $run instanceof WorkflowRunSnapshot ? self::shapeOf($run) : null;
    }

    /**
     * @return string[] every value the workflow store holds now, as the engine wrote it
     */
    public function stored(): array
    {
        $values = [];
        foreach ($this->home->store()->query('SELECT "value" FROM workflow_store') ?: [] as $record) {
            $values[] = base64_decode((string) $record['value']);
        }

        return $values;
    }

    /**
     * The queue of the shop: what waits in a list, and every message it ever carried.
     */
    public function queue(): Spool
    {
        return new Spool($this->home->queue());
    }

    /**
     * Stop every process of the shop.
     */
    public function stop(): void
    {
        $this->stopServing();

        foreach ($this->processes as $process) {
            $process->kill();
        }
    }

    /**
     * @return array<string, string> what every process of the shop wrote, by the name of its log
     */
    public function logs(): array
    {
        $logs = ['web' => (string) $this->web?->log()];
        foreach ($this->processes as $name => $process) {
            $logs[$name] = $process->log();
        }

        return $logs;
    }

    /**
     * Run a script of bin to its end.
     *
     * @param string[] $arguments
     * @param array<string, string> $environment
     * @throws RuntimeException when the script does not end
     */
    protected function run(string $script, array $arguments = [], array $environment = []): Process
    {
        return $this->ended($this->spawn($script, $arguments, $environment), $script);
    }

    /**
     * @throws RuntimeException when the script does not end
     */
    protected function ended(Process $process, string $script): Process
    {
        if ($process->exitCode() === null && $process->isRunning()) {
            $process->kill();

            throw new RuntimeException("bin/{$script}.php of the shop did not end: " . $process->log());
        }

        return $process;
    }

    /**
     * @param string[] $arguments
     * @param array<string, string> $environment
     */
    protected function spawn(string $script, array $arguments, array $environment): Process
    {
        $name = implode('-', [$script, ...$arguments]) . '-' . (count($this->processes) + 1);

        return $this->processes[$name] = new Process(
            [PHP_BINARY, $this->script("bin/{$script}.php"), ...$arguments],
            $environment + $this->environment,
            "{$this->directory}/{$name}.log",
        );
    }

    protected function script(string $path): string
    {
        return __DIR__ . '/../' . self::APPLICATION . '/' . $path;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function shapeOf(WorkflowRunSnapshot $run): array
    {
        $shape = [
            'runId' => $run->runId,
            // Every run of the shop is started through the SDK, which tags it with the name of its definition.
            'definition' => $run->tag,
            'status' => $run->status->value,
            'attempt' => $run->executionAttempt,
            'leaseExpiresAt' => $run->leaseExpiresAt,
        ];

        $wait = $run->interrupt;
        if ($run->status === WorkflowStatus::Suspended && $wait instanceof \NeuronAI\Workflow\Interrupt\InterruptRequest) {
            $shape['wait'] = [
                'interruptId' => $wait->getId(),
                'type' => $wait->type()->value,
                'eventName' => $wait instanceof WaitForEventRequest ? $wait->getEventName() : null,
                'wakeAt' => match (true) {
                    $wait instanceof SleepUntilRequest => $wait->getWakeAt()->getTimestamp(),
                    $wait instanceof WaitForEventRequest => $wait->getExpiresAt()?->getTimestamp(),
                    default => null,
                },
            ];
        }

        return $shape;
    }
}
