<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Flows;

/**
 * Strings planted wherever a flow carries what must never leave the
 * application. Each is unlike anything the SDK or Neuron writes, so finding
 * one in a request to the platform is a leak.
 */
final class Planted
{
    public const START_EVENT = 'PLANTED-7c41e9-in-the-start-event';

    public const MESSAGE = 'PLANTED-b25f08-in-a-message-of-the-user';

    public const REPLY = 'PLANTED-49d7a3-in-the-reply-of-the-model';

    public const TOOL_INPUT = 'PLANTED-e6130b-in-a-tool-input';

    public const ANSWER = 'PLANTED-0fa8c2-in-an-answer';

    public const SIGNAL = 'PLANTED-d39b56-in-a-signal';

    public const EXCEPTION = 'PLANTED-85e2f7-in-the-message-of-an-exception';

    /**
     * @return array<string, string> where it is planted => the marker, for what the order of the plain PHP example carries
     */
    public static function inAnOrder(): array
    {
        return [
            'the start event' => self::START_EVENT,
            'the signal payload' => self::SIGNAL,
            'the message of the exception of a node' => self::EXCEPTION,
        ];
    }

    /**
     * @return array<string, string> where it is planted => the marker, for what a chat turn with an approval carries
     */
    public static function inAChatTurn(): array
    {
        return [
            'a message of the user' => self::MESSAGE,
            'the reply of the model' => self::REPLY,
            'a tool input' => self::TOOL_INPUT,
            'the answer payload' => self::ANSWER,
        ];
    }
}
