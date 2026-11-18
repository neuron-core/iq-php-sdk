<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\StreamedChat;

/**
 * Strings planted wherever a chat turn carries what must never leave the
 * application. Each is unlike anything the SDK or Neuron writes, so finding
 * one in a request to the platform is a leak.
 */
final class Confidential
{
    public const MESSAGE = 'CONFIDENTIAL-41c7e0-in-the-message-of-the-user';

    public const TOKENS = 'CONFIDENTIAL-9ab3f2-in-the-tokens-of-the-model';

    public const TOOL_INPUT = 'CONFIDENTIAL-d05e61-in-a-tool-input';

    public const TOOL_RESULT = 'CONFIDENTIAL-6f28bd-in-a-tool-result';

    public const APPROVAL = 'CONFIDENTIAL-b7149c-in-the-decision-of-the-approver';

    public const EXCEPTION = 'CONFIDENTIAL-e83a57-in-the-message-of-an-exception';

    /**
     * @return array<string, string> where it is planted => the marker
     */
    public static function all(): array
    {
        return [
            'the message of the user' => self::MESSAGE,
            'the tokens of the model' => self::TOKENS,
            'a tool input' => self::TOOL_INPUT,
            'a tool result' => self::TOOL_RESULT,
            'the decision of the approver' => self::APPROVAL,
            'the message of the exception of a tool' => self::EXCEPTION,
        ];
    }
}
