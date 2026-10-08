<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\InPlace;

use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use Throwable;

/**
 * A stream adapter that streams nothing and notes what the segment it serves
 * asked it for: a test reads there that the segment went through it.
 */
class SilentAdapter implements StreamAdapterInterface
{
    /** @var string[] the sequences the segment asked for, in order */
    public array $asked = [];

    public function transform(object $chunk): iterable
    {
        return [];
    }

    public function start(): iterable
    {
        $this->asked[] = 'start';

        return [];
    }

    public function end(): iterable
    {
        $this->asked[] = 'end';

        return [];
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        $this->asked[] = 'interrupt';

        return [];
    }

    public function error(Throwable $error): iterable
    {
        $this->asked[] = 'error';

        return [];
    }
}
