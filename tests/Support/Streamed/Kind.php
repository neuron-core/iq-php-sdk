<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Streamed;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Verb;

/**
 * The kinds of segment an application streams in its own process.
 */
enum Kind
{
    case Start;

    case Answer;

    case Signal;

    public function verb(): Verb
    {
        return match ($this) {
            self::Start => Verb::Start,
            self::Answer => Verb::Answer,
            self::Signal => Verb::Signal,
        };
    }

    public function continuesARun(): bool
    {
        return $this !== self::Start;
    }

    /**
     * The attempt Neuron opens a segment of this kind under: a continuation takes over a run that suspended once.
     */
    public function attempt(): int
    {
        return $this->continuesARun() ? 2 : 1;
    }

    /**
     * The words that name a segment of this kind in a data set.
     */
    public function label(): string
    {
        return match ($this) {
            self::Start => 'a start',
            self::Answer => 'an answer',
            self::Signal => 'a signal',
        };
    }

    /**
     * What an application without the SDK asks Neuron for a segment of this kind.
     *
     * @param array<string, mixed> $answer what an answer or a signal delivers
     */
    public function request(string $runId, array $answer): ExecutionRequest
    {
        return match ($this) {
            self::Start => ExecutionRequest::start(new StartEvent(), $runId),
            self::Answer => ExecutionRequest::resume($answer, $runId, 1),
            self::Signal => ExecutionRequest::signal(TaleWorkflow::EVENT, $answer, $runId, 1),
        };
    }
}
