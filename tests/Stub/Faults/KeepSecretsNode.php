<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Faults;

use DateTimeImmutable;
use NeuronAI\Agent\Interrupt\Action;
use NeuronAI\Agent\Interrupt\ApprovalRequest;
use NeuronAI\Workflow\Node;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\IQ\Tests\Stub\PaymentSettled;
use NeuronCore\IQ\Tests\Support\Faults\Markers;

/**
 * Keeps a marker in the state and in a memo, then waits three times: for an
 * approval whose message and tool input are markers, for an event, and for a
 * timer that is due.
 */
class KeepSecretsNode extends Node
{
    public function __invoke(SecretStart $event, WorkflowState $state): PaymentSettled
    {
        $state->set('input', $event->input);
        $state->set('customer', Markers::STATE);
        $state->set('quote', $this->memoize('quote', static fn (): string => Markers::MEMO));

        $state->set('decision', $this->interrupt(new ApprovalRequest(
            Markers::APPROVAL_MESSAGE,
            [new Action('call_1', 'refund', inputs: ['iban' => Markers::TOOL_INPUT])],
        )));
        $state->set('payment', $this->awaitEvent(SecretiveWorkflow::EVENT));
        $this->sleepUntil(new DateTimeImmutable('@0'));

        return new PaymentSettled();
    }
}
