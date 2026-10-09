<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use NeuronAI\Chat\History\SQLMessageStore;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;
use PDO;

use function array_map;
use function implode;

/**
 * The durable chat history of the application: Neuron's SQL message store on
 * an SQLite file, which every process and every agent opens for itself.
 */
final class Transcripts
{
    /**
     * @param string $path an SQLite file; the table is created when it is not there
     */
    public function __construct(public readonly string $path)
    {
        $this->connect()->exec(
            'CREATE TABLE IF NOT EXISTS chat_messages ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'thread_id VARCHAR(255) NOT NULL, '
            . 'message_id VARCHAR(64) NOT NULL, '
            . 'role VARCHAR(32) NOT NULL, '
            . 'content TEXT NULL, '
            . 'meta TEXT NULL, '
            . 'archived_at DATETIME NULL, '
            . 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, '
            . 'UNIQUE (thread_id, message_id))'
        );
    }

    public function store(): SQLMessageStore
    {
        return new SQLMessageStore($this->connect());
    }

    /**
     * @return string[] "role: text" for every message stored for a thread, in order; a tool result is "tool: result"
     */
    public function of(string $threadId): array
    {
        return array_map(
            static fn (Message $message): string => $message instanceof ToolResultMessage
                ? 'tool: ' . implode(' ', array_map(static fn (ToolCall $call): string => (string) $call->getResult(), $message->getToolCalls()))
                : $message->getRole() . ': ' . $message->getContent(),
            $this->store()->loadAll($threadId),
        );
    }

    protected function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->path);
        // Several processes may write the same file: wait for the lock instead of failing.
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }
}
