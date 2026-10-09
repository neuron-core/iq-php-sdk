<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Streamed;

use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Workflow\Interrupt\InterruptRequest;
use NeuronAI\Workflow\Streaming\Adapter\StreamAdapterInterface;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use Throwable;

use const NAN;

/**
 * A stream adapter that frames the life of a segment and echoes in each
 * frame what Neuron gave it: the word of a chunk, the wait of an
 * interruption, the class of an error. A failure closes the protocol in two
 * frames, as AG-UI does.
 */
class EchoingAdapter implements StreamAdapterInterface
{
    /**
     * @param string|null $unencodable a word whose frame no JSON encoder can write
     */
    public function __construct(protected ?string $unencodable = null)
    {
    }

    public function transform(object $chunk): iterable
    {
        $word = $chunk instanceof TextChunk ? $chunk->content : null;

        return [new ProtocolEvent('word', ['text' => $word, 'weight' => $word === $this->unencodable ? NAN : 1.0])];
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
        return [new ProtocolEvent('interrupt', ['wait' => $request->getId()])];
    }

    public function error(Throwable $error): iterable
    {
        return [new ProtocolEvent('error', ['class' => $error::class]), new ProtocolEvent('closed')];
    }
}
