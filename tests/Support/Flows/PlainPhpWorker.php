<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

use NeuronCore\IQ\IQException;
use NeuronCore\IQ\Code;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\IQ;

use function in_array;
use function json_decode;
use function time;

/**
 * The worker of the plain PHP example, as the document writes it: one
 * message, one call, and what invoke() throws for decides where the message
 * goes next. It catches nothing else, so anything invoke() lets through that
 * is not an IQException ends the test.
 */
final class PlainPhpWorker
{
    public const QUEUE = 'iq';

    /** What a cron moves back to the queue every minute. */
    public const LATER = 'iq:later';

    /** What a person replays: refused for good by the platform, or past its use-by time. */
    public const FAILED = 'iq:failed';

    public function __construct(protected FakeRedis $redis)
    {
    }

    /**
     * bin/worker.php, until the queue is empty.
     */
    public function work(IQ $iq): void
    {
        while (($job = $this->redis->lPop(self::QUEUE)) !== null) {
            $invocation = Invocation::fromArray(json_decode($job, true));

            try {
                $iq->invoke($invocation);
            } catch (IQException $e) {
                $final = in_array($e->reason, [Code::Unauthorized, Code::InvalidRequest, Code::UnsupportedProtocol], true);
                $spent = $e->reason !== Code::ReportUndelivered && $invocation->expiresAt !== null && time() >= $invocation->expiresAt;
                $this->redis->rPush($final || $spent ? self::FAILED : self::LATER, $job);
            }
        }
    }

    /**
     * The cron of the example: it moves the "later" list back to the queue.
     */
    public function retryLater(): void
    {
        while (($job = $this->redis->lPop(self::LATER)) !== null) {
            $this->redis->rPush(self::QUEUE, $job);
        }
    }
}
