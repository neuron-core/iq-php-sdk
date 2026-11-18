<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\StreamedChat;

use Generator;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Testing\FakeAIProvider;

use function usleep;

/**
 * Neuron's fake provider at the pace of a real one: it waits between two
 * chunks of an answer it streams, so that a client can leave in the middle.
 */
class PacedModel extends FakeAIProvider
{
    /**
     * @param int $pause microseconds between two chunks
     */
    public function __construct(protected int $pause, Message ...$responses)
    {
        parent::__construct(...$responses);
    }

    protected function streamChunks(Message $response): Generator
    {
        $chunks = parent::streamChunks($response);

        foreach ($chunks as $chunk) {
            yield $chunk;
            usleep($this->pause);
        }

        return $chunks->getReturn();
    }
}
