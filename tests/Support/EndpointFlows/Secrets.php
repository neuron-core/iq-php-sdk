<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\EndpointFlows;

/**
 * Strings planted wherever an order carries what must never leave the
 * application. Each is unlike anything the SDK or Neuron writes, so finding
 * one on the wire is a leak.
 */
final class Secrets
{
    public const STATE = 'SECRET-41c7d0-kept-in-the-state';

    public const START_EVENT = 'SECRET-9a3e56-carried-by-the-start-event';

    public const SIGNAL = 'SECRET-b08f21-in-the-payload-of-the-signal';

    public const EXCEPTION = 'SECRET-6d92ac-in-the-message-of-an-exception';

    /**
     * @return array<string, string> where it is planted => the marker
     */
    public static function planted(): array
    {
        return [
            'the state of the run' => self::STATE,
            'the start event' => self::START_EVENT,
            'the payload of the signal' => self::SIGNAL,
            'the message of the exception of a node' => self::EXCEPTION,
        ];
    }
}
