<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Flows;

use NeuronCore\Cloud\DispatcherInterface;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\Tests\Support\Flows\FakeRedis;
use NeuronCore\Cloud\Tests\Support\Flows\PlainPhpWorker;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The dispatcher of the plain PHP example, as the document writes it.
 */
class RedisDispatcher implements DispatcherInterface
{
    public function __construct(protected FakeRedis $redis)
    {
    }

    public function dispatch(Invocation $invocation): void
    {
        $this->redis->rPush(PlainPhpWorker::QUEUE, json_encode($invocation->toArray(), JSON_THROW_ON_ERROR));
    }
}
