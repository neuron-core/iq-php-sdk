<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Units;

use LogicException;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use Throwable;

/**
 * A framing adapter that cannot build one of its frames: it throws where it
 * should say that the segment ended, suspended or failed.
 */
class UnframableAdapter extends FramingAdapter
{
    /**
     * @param string $unframable the method that throws: "end", "interrupt" or "error"
     */
    public function __construct(protected string $unframable)
    {
    }

    public function end(): iterable
    {
        return $this->unframable === 'end' ? throw $this->cannotFrame() : parent::end();
    }

    public function interrupt(InterruptRequest $request): iterable
    {
        return $this->unframable === 'interrupt' ? throw $this->cannotFrame() : parent::interrupt($request);
    }

    public function error(Throwable $error): iterable
    {
        return $this->unframable === 'error' ? throw $this->cannotFrame() : parent::error($error);
    }

    protected function cannotFrame(): LogicException
    {
        return new LogicException("The adapter cannot frame the {$this->unframable} of a segment.");
    }
}
