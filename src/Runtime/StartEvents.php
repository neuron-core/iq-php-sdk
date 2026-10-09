<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Agent\Agent;
use NeuronAI\Agent\Events\AgentStartEvent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Executor\ExecutionRequest;
use NeuronAI\Workflow\Workflow;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use Throwable;

use function base64_decode;
use function base64_encode;
use function get_debug_type;
use function is_array;
use function serialize;
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
        return self::checked($workflow, $carried === null ? null : self::decode($carried));
    }

    /**
     * The event a start in the application's own code ignites its run with:
     * the one it is given, the agent's own with the messages it is given, or
     * the workflow's own.
     *
     * @param Event|Message|Message[]|null $input
     * @param bool $stream whether the provider streams its answer to the messages
     * @throws CloudException invalid_start, when the event breaks a rule
     * @throws Throwable what a hook of the workflow threw while its event or its graph was built: no rule is broken
     */
    public static function from(Workflow $workflow, Event|Message|array|null $input, bool $stream): Event
    {
        return $input instanceof Event || $input === null
            ? self::checked($workflow, $input)
            : self::conversation($workflow, is_array($input) ? $input : [$input], $stream);
    }

    /**
     * The request that ignites a run with a checked event, under a run ID the
     * SDK reserved. The run carries the name of its definition as its tag.
     *
     * @throws CloudException invalid_start, when Neuron cannot store the event or the run ID with a run
     */
    public static function request(Event $event, string $runId, string $definition): ExecutionRequest
    {
        try {
            // recoverFailed stays false: a start only ever creates a run, so a repeated start can never become a retry.
            return ExecutionRequest::start($event, $runId, recoverFailed: false, tag: $definition);
        } catch (Throwable $e) {
            throw new CloudException('The start cannot be stored with a run: ' . $e->getMessage(), Code::InvalidStart, $e);
        }
    }

    /**
     * The form in which a start event travels in the application's queue.
     */
    public static function encode(Event $event): string
    {
        return base64_encode(serialize($event));
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
     * @param Event|null $given null: the workflow's own start event
     * @throws CloudException
     * @throws Throwable
     */
    protected static function checked(Workflow $workflow, ?Event $given): Event
    {
        $event = $given ?? $workflow->getStartEvent();

        if ($workflow instanceof Agent) {
            self::assertStartsAgent($event, $given instanceof Event ? $workflow->getStartEvent() : $event);
        } else {
            self::assertHandled($workflow, $event);
        }

        return $event;
    }

    /**
     * A copy of the agent's own start event, so its startEvent() hook is
     * respected, with the messages of this turn.
     *
     * @param Message[] $messages
     * @throws CloudException
     */
    protected static function conversation(Workflow $workflow, array $messages, bool $stream): AgentStartEvent
    {
        $own = $workflow instanceof Agent ? $workflow->getStartEvent() : null;

        if (!$own instanceof AgentStartEvent) {
            throw new CloudException(
                'Messages start an agent whose own start event is an AgentStartEvent: start this workflow with an event.',
                Code::InvalidStart,
            );
        }

        $event = clone $own;
        $event->messages = $messages;
        // The options are an object: the copy must not share them with the event the agent holds.
        $event->options = clone $own->options;
        $event->options->stream = $stream;

        self::assertStartsAgent($event, $own);

        return $event;
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

        foreach ($event->messages as $message) {
            if (!$message instanceof Message) {
                throw new CloudException(
                    'An agent starts with Message objects: the list holds ' . get_debug_type($message) . '.',
                    Code::InvalidStart,
                );
            }
        }
    }
}
