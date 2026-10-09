<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use NeuronAI\Workflow\Streaming\Channel\CallbackChannel;
use NeuronAI\Workflow\Streaming\ProtocolEvent;
use NeuronAI\Workflow\Streaming\SSEEncoder;

/**
 * Where the agent's own channel hook delivers: what a segment that no
 * response streams sent to the browsers that listen to a thread, and how each
 * of those segments ended.
 */
final class Broadcast
{
    /** @var array<string, string[]> thread ID => the frames delivered, in order */
    protected array $frames = [];

    /** @var array<string, string[]> thread ID => 'suspended', 'completed' or 'failed' for every segment, in order */
    protected array $endings = [];

    /**
     * The channel of one segment of a thread.
     */
    public function channelOf(string $threadId): CallbackChannel
    {
        return new CallbackChannel(
            onSend: function (ProtocolEvent $event) use ($threadId): void {
                $this->frames[$threadId][] = SSEEncoder::frame($event);
            },
            onInterrupted: function () use ($threadId): void {
                $this->endings[$threadId][] = 'suspended';
            },
            onCompleted: function () use ($threadId): void {
                $this->endings[$threadId][] = 'completed';
            },
            onFailed: function () use ($threadId): void {
                $this->endings[$threadId][] = 'failed';
            },
        );
    }

    /**
     * @return string[]
     */
    public function framesOf(string $threadId): array
    {
        return $this->frames[$threadId] ?? [];
    }

    /**
     * @return string[]
     */
    public function endingsOf(string $threadId): array
    {
        return $this->endings[$threadId] ?? [];
    }
}
