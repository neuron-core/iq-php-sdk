<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\ExampleApp;

use NeuronCore\IQ\DispatcherInterface;
use NeuronCore\IQ\Invocation;
use NeuronCore\IQ\Verb;

use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The dispatcher of the shop, as the example of the design writes its
 * RedisDispatcher. The SDK's own notices go to a list of their own, so that
 * a platform outage cannot delay real segments (section 10 of the design).
 */
final class SpoolDispatcher implements DispatcherInterface
{
    /** The orders to execute one segment: a start, a wake, an answer or a signal. */
    public const SEGMENTS = 'iq';

    /** The announcements and the observes to post. */
    public const NOTICES = 'iq-notices';

    public function __construct(protected Spool $spool)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $list = in_array($invocation->verb, [Verb::Announce, Verb::Observe], true) ? self::NOTICES : self::SEGMENTS;

        $this->spool->push($list, json_encode($invocation->toArray(), JSON_THROW_ON_ERROR));
    }
}
