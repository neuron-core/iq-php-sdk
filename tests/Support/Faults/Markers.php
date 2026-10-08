<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Faults;

/**
 * Strings planted wherever a workflow keeps what must never leave the
 * application. Each is unlike anything the SDK or Neuron writes, so finding
 * one in a request is a leak.
 */
final class Markers
{
    public const STATE = 'MARKER-5d1f0c-in-the-state';

    public const MEMO = 'MARKER-a82e47-in-a-memo';

    public const START_EVENT = 'MARKER-3b96d1-in-the-start-event';

    public const ANSWER = 'MARKER-e04c7a-in-an-answer';

    public const SIGNAL = 'MARKER-91f2b8-in-a-signal';

    public const APPROVAL_MESSAGE = 'MARKER-c6d530-in-the-message-of-an-approval';

    public const TOOL_INPUT = 'MARKER-27ab9e-in-a-tool-input-of-an-approval';

    public const EXCEPTION = 'MARKER-f81d63-in-the-message-of-an-exception';

    /**
     * @return array<string, string> where it is planted => the marker, for those a run writes to its store
     */
    public static function stored(): array
    {
        return [
            'the state' => self::STATE,
            'a memo' => self::MEMO,
            'the start event' => self::START_EVENT,
            'an answer payload' => self::ANSWER,
            'a signal payload' => self::SIGNAL,
            'the message of an approval request' => self::APPROVAL_MESSAGE,
            'a tool input of an approval request' => self::TOOL_INPUT,
        ];
    }

    /**
     * @return array<string, string> where it is planted => the marker
     */
    public static function all(): array
    {
        return self::stored() + ['the message of an exception' => self::EXCEPTION];
    }
}
