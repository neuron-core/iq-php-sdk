<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Testing;

use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Report;
use NeuronCore\Cloud\Verb;
use PHPUnit\Framework\Assert;

use function array_filter;
use function in_array;
use function json_decode;
use function json_encode;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * A queue held in memory: it records every message it is handed and runs
 * them only when the test says so.
 */
class FakeDispatcher implements DispatcherInterface
{
    /** What a real worker keeps aside for a person: the platform refused the message for good. */
    protected const FINAL_REASONS = [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol];

    /** @var Invocation[] every message ever dispatched */
    protected array $dispatched = [];

    /** @var string[] the messages waiting to run, JSON-encoded as a real queue would hold them */
    protected array $queue = [];

    public function dispatch(Invocation $invocation): void
    {
        $this->queue[] = json_encode($invocation->toArray(), JSON_THROW_ON_ERROR);
        $this->dispatched[] = $invocation;
    }

    /**
     * Run what is queued now through invoke(), each message $times times in a
     * row: 2 proves a second delivery harmless. A message that invoke() throws
     * for stays queued for the next call, as a worker would try it again later,
     * unless the platform refused it for good or its use-by time has passed.
     *
     * @return array<int, Report|CloudException> what every invoke() returned or threw, in order
     */
    public function runAll(NeuronCloud $cloud, int $times = 1): array
    {
        $messages = $this->queue;
        $this->queue = [];
        $results = [];

        foreach ($messages as $message) {
            $invocation = Invocation::fromArray(json_decode($message, true, flags: JSON_THROW_ON_ERROR));

            for ($delivery = 1; $delivery <= $times; $delivery++) {
                try {
                    $results[] = $cloud->invoke($invocation);
                } catch (CloudException $notDealtWith) {
                    $results[] = $notDealtWith;

                    if ($delivery === 1 && $this->triesAgain($invocation, $notDealtWith)) {
                        $this->queue[] = $message;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * What a worker does with a message invoke() threw for: it keeps aside one
     * the platform refused for good and one that is past its use-by time, and
     * tries any other again. An undelivered notice has no time limit.
     */
    protected function triesAgain(Invocation $invocation, CloudException $notDealtWith): bool
    {
        $final = in_array($notDealtWith->reason, self::FINAL_REASONS, true);
        $spent = $notDealtWith->reason !== Code::ReportUndelivered
            && $invocation->expiresAt !== null
            && time() >= $invocation->expiresAt;

        return !$final && !$spent;
    }

    // ----------------------------------------------------------------
    // PHPUnit Assertions
    // ----------------------------------------------------------------

    public function assertDispatched(Verb $verb, ?string $workflowId = null): void
    {
        $matching = array_filter(
            $this->dispatched,
            static fn (Invocation $invocation): bool => $invocation->verb === $verb
                && ($workflowId === null || $invocation->workflowId === $workflowId),
        );

        Assert::assertNotEmpty(
            $matching,
            "No '{$verb->value}' was dispatched" . ($workflowId === null ? '.' : " for workflow ID '{$workflowId}'."),
        );
    }
}
