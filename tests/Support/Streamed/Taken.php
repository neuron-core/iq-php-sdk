<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Streamed;

use Closure;
use Generator;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\WorkflowState;
use Throwable;

use function array_column;

/**
 * What a consumer got from a stream it took to its end.
 */
final class Taken
{
    /**
     * @param array<int, array{int|string, object}> $frames the key and the event of every step of the stream, in order
     * @param WorkflowState|null $returned what getReturn() gave; null when the stream threw
     * @param Throwable|null $thrown what the stream threw
     * @param array<int, mixed> $notedAtEachFrame what the consumer noted while it held each frame
     */
    protected function __construct(
        public readonly array $frames,
        public readonly ?WorkflowState $returned,
        public readonly ?Throwable $thrown,
        public readonly array $notedAtEachFrame,
    ) {
    }

    /**
     * Take a stream to its end, as a foreach does.
     *
     * @param Generator<int, object, mixed, WorkflowState> $events
     * @param (Closure(): mixed)|null $note called while the consumer holds each frame
     */
    public static function from(Generator $events, ?Closure $note = null): self
    {
        $frames = [];
        $noted = [];

        try {
            foreach ($events as $key => $frame) {
                $frames[] = [$key, $frame];
                $noted[] = $note instanceof Closure ? $note() : null;
            }

            return new self($frames, $events->getReturn(), null, $noted);
        } catch (Throwable $thrown) {
            return new self($frames, null, $thrown, $noted);
        }
    }

    /**
     * @return object[] the events alone, in order
     */
    public function events(): array
    {
        return array_column($this->frames, 1);
    }

    /**
     * @return string[] the word of every chunk of text the stream gave, in order
     */
    public function words(): array
    {
        $words = [];
        foreach ($this->events() as $event) {
            if ($event instanceof TextChunk) {
                $words[] = $event->content;
            }
        }

        return $words;
    }
}
