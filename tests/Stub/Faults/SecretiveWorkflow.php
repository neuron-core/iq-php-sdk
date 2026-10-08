<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Faults;

use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\Tests\Support\Faults\Markers;

/**
 * Holds a marker everywhere a workflow keeps what must not leave the
 * application: its start event, its state, a memo, an approval request and
 * the message of an exception. Three waits and one failure stand between its
 * start and its end.
 */
class SecretiveWorkflow extends Workflow
{
    public const EVENT = 'payment.received';

    protected function startEvent(): Event
    {
        return new SecretStart(Markers::START_EVENT);
    }

    protected function nodes(): array
    {
        return [new KeepSecretsNode(), new ThrowSecretNode()];
    }
}
