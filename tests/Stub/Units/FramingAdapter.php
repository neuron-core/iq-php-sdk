<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Units;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

/**
 * A stream adapter that frames the life of a segment: one event when it
 * starts, one for each item a node streams, and one when it ends, suspends or
 * fails. Neuron yields the last one after it has settled the run.
 */
class FramingAdapter implements StreamAdapterInterface
{
    public function transform(object $chunk): iterable
    {
        return [new ProtocolEvent('item')];
    }

    public function start(): iterable
    {
        return [new ProtocolEvent('start')];
    }

    public function end(): iterable
    {
        return [new ProtocolEvent('end')];
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        return [new ProtocolEvent('interrupt')];
    }

    public function error(Throwable $error): iterable
    {
        return [new ProtocolEvent('error')];
    }
}
