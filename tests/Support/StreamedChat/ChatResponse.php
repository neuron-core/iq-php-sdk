<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use Generator;
use Throwable;

use function iterator_to_array;
use function str_contains;

/**
 * What the endpoint answers a browser: a status and, when the turn was
 * admitted, a body that is produced while it is read.
 */
final class ChatResponse
{
    /**
     * @param array<string, string> $headers
     * @param Generator<int, string>|null $body the frames of the stream; null when the request was refused
     * @param Throwable|null $refusal what the status was decided from
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers = [],
        protected ?Generator $body = null,
        public readonly ?Throwable $refusal = null,
    ) {
    }

    /**
     * The body, for whoever sends it. The response lets go of it: once its
     * reader stops, nothing holds the stream any more.
     *
     * @return Generator<int, string>
     */
    public function frames(): Generator
    {
        $body = $this->body;
        $this->body = null;

        yield from $body ?? [];
    }

    /**
     * A browser that reads the response to its end.
     *
     * @return string[] the frames it received
     */
    public function read(): array
    {
        return iterator_to_array($this->frames(), false);
    }

    /**
     * A browser that closes the connection once it has received the first frame of a type.
     *
     * @param string $type the type of an AG-UI event
     * @return string[] the frames it received, that one included
     */
    public function readUntil(string $type): array
    {
        $received = [];

        foreach ($this->frames() as $frame) {
            $received[] = $frame;

            if (str_contains($frame, '"type":"' . $type . '"')) {
                break;
            }
        }

        return $received;
    }
}
