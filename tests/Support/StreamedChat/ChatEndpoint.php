<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\StreamedChat;

use Closure;
use Generator;
use NeuronAI\Agent\Adapters\AGUIAdapter;
use NeuronAI\Agent\Interrupt\ApprovalTranslator;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Exceptions\WorkflowException;
use NeuronAI\Workflow\Streaming\Channel\StreamingChannelInterface;
use NeuronAI\Workflow\Streaming\SSEEncoder;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\StreamedChat\SupportAgent;
use Throwable;

use function in_array;

/**
 * The streaming endpoint of example (b) of section 5 of the design, as plain
 * PHP: the controller, and the mapping of the guide that turns a refusal into
 * an HTTP status. Everything it does not map is a programming error and is
 * thrown as it is, where a framework would answer 500.
 */
final class ChatEndpoint
{
    public const DEFINITION = 'support-agent';

    /**
     * @param (Closure(Throwable): void)|null $report where the application reports what failed a stream it had begun
     */
    public function __construct(protected NeuronCloud $cloud, protected ?Closure $report = null)
    {
    }

    /**
     * @param array{threadId: string, runId: string, message?: string, decisions?: array<string, mixed>} $request a message
     *        of the user, or the decisions of a person on the pending approvals
     * @throws Throwable what the mapping of the guide does not render
     */
    public function __invoke(array $request): ChatResponse
    {
        try {
            return $this->stream($request);
        } catch (InputTranslationException $refusal) {
            return new ChatResponse(400, refusal: $refusal);
        } catch (WorkflowException $refusal) {
            // RunInFlightException: an approval is pending or a turn is executing. Any other: a stale continuation.
            return new ChatResponse(409, refusal: $refusal);
        } catch (CloudException $refusal) {
            return in_array($refusal->reason, [Code::DispatchFailed, Code::UnknownVersion], true)
                ? new ChatResponse(503, refusal: $refusal)
                : throw $refusal;
        }
    }

    /**
     * @param array{threadId: string, runId: string, message?: string, decisions?: array<string, mixed>} $request
     * @throws Throwable
     */
    protected function stream(array $request): ChatResponse
    {
        $thread = $this->cloud->workflow(self::DEFINITION, $request['threadId']);
        $thread->recover();

        $adapter = new AGUIAdapter(threadId: $request['threadId'], runId: $request['runId']);
        $thread->configure(static fn (SupportAgent $agent): SupportAgent => $agent
            ->setStreamAdapter(static fn (): AGUIAdapter => $adapter)
            // This segment streams in the response, not on the channel.
            ->setChannel(static fn (): ?StreamingChannelInterface => null));

        $events = isset($request['decisions'])
            ? $thread->submitInputs($request['decisions'], new ApprovalTranslator())->events()
            : $thread->start(new UserMessage($request['message']), stream: true)->events();

        // Admit now: a refusal is an HTTP status, not a frame.
        $events->valid();

        return new ChatResponse(200, $adapter->getHeaders(), $this->body($events, $adapter));
    }

    /**
     * @param Generator<int, object, mixed, WorkflowState> $events
     * @return Generator<int, string>
     */
    protected function body(Generator $events, AGUIAdapter $adapter): Generator
    {
        try {
            yield from SSEEncoder::encode($events);
        } catch (Throwable $failure) {
            if ($this->report instanceof Closure) {
                ($this->report)($failure);
            }

            foreach ($adapter->error($failure) as $event) {
                yield SSEEncoder::frame($event);
            }
        }
    }
}
