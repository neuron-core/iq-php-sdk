<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use Throwable;

use function base64_decode;
use function unserialize;

/**
 * The start event of a run is immutable once the run exists, so a bad one
 * must never create a run: it is checked before anything is claimed or ignited.
 *
 * @internal
 */
final class StartEvents
{
    /**
     * The event a queued start ignites its run with: the one it carries, or
     * the workflow's own.
     *
     * @param string|null $carried the event of the invocation, PHP-serialized and base64-encoded
     * @throws CloudException invalid_start, when the event breaks a rule
     * @throws Throwable what a hook of the workflow threw while its event or its graph was built: no rule is broken
     */
    public static function resolve(Workflow $workflow, ?string $carried): Event
    {
        $event = $carried === null ? $workflow->getStartEvent() : self::decode($carried);

        if ($workflow instanceof Agent) {
            self::assertStartsAgent($event, $carried === null ? $event : $workflow->getStartEvent());
        } else {
            self::assertHandled($workflow, $event);
        }

        return $event;
    }

    /**
     * @throws CloudException
     */
    protected static function decode(string $carried): Event
    {
        try {
            $event = unserialize((string) base64_decode($carried, true));
        } catch (Throwable) {
            // Where warnings are exceptions, unserialize() throws for bytes it cannot read.
            $event = null;
        }

        return $event instanceof Event
            ? $event
            : throw new CloudException('The queued start event cannot be rebuilt in this process.', Code::InvalidStart);
    }

    /**
     * A dry build of the graph proves that a node handles the event: Neuron
     * refuses the graph when none does, and touches no store.
     *
     * @throws CloudException
     * @throws Throwable
     */
    protected static function assertHandled(Workflow $workflow, Event $event): void
    {
        try {
            (clone $workflow)->setStartEvent($event)->export();
        } catch (WorkflowException $refused) {
            // The graph refuses with a plain WorkflowException. A subclass, such as a store's, came from a hook.
            if ($refused::class !== WorkflowException::class) {
                throw $refused;
            }

            throw new CloudException('The start event was refused: ' . $refused->getMessage(), Code::InvalidStart, $refused);
        }
    }

    /**
     * @param Event $own the agent's own start event
     * @throws CloudException
     */
    protected static function assertStartsAgent(Event $event, Event $own): void
    {
        if (!$event instanceof AgentStartEvent || $event::class !== $own::class) {
            throw new CloudException(
                'An agent starts with an event of the class of its own start event, ' . $own::class . '.',
                Code::InvalidStart,
            );
        }

        if ($event->messages === []) {
            throw new CloudException(
                'An agent cannot start with no messages: it would ignite and then fail on an empty conversation.',
                Code::InvalidStart,
            );
        }
    }
}
