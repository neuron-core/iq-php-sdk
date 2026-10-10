<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\ExampleApp;

/**
 * Strings the shop plants wherever an order carries what must never leave
 * the application. Each is unlike anything the SDK or Neuron writes, so
 * finding one outside the application is a leak.
 */
final class Confidential
{
    /** Kept in the state of every run. */
    public const STATE = 'CONFIDENTIAL-7f3a91-kept-in-the-state-of-the-run';

    /** Carried by the start event of an order the platform starts, which the workflow builds itself. */
    public const START_EVENT = 'CONFIDENTIAL-c25e08-carried-by-the-start-event';

    /** The message of the exception a planned failure throws. */
    public const EXCEPTION = 'CONFIDENTIAL-1b84d6-in-the-message-of-an-exception';
}
