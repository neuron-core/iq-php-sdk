<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Runtime;

use NeuronAI\Exceptions\AgentException;
use NeuronAI\Exceptions\StaleWorkflowRunException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Code;
use NeuronCore\Cloud\DispatcherInterface;
use Throwable;

use function array_filter;
use function implode;
use function is_string;
use function json_encode;
use function strlen;
use function strtolower;
use function strtoupper;
use function time;

use const JSON_THROW_ON_ERROR;

/**
 * The signed URL the platform calls. A start and a wake are handed to the
 * queue; an inspect, an abandon and a ping are answered in the request.
 *
 * @internal
 */
final class Endpoint
{
    /** Seconds the platform is asked to wait before it sends again a message the queue or the store did not take. */
    protected const RETRY_AFTER = 5;

    /**
     * Bytes. The largest valid message of protocol 1 is under 4 KB, and decoding a body of megabytes can end
     * in a fatal error that handle() cannot catch.
     */
    protected const LONGEST_BODY = 65536;

    public function __construct(
        protected Signature $signature,
        protected Registry $registry,
        protected WorkflowBuilder $builder,
        protected Ledger $ledger,
        protected DispatcherInterface $dispatcher,
    ) {
    }

    /**
     * It never throws, so the exception renderers of the application never answer the platform.
     *
     * @param array<array-key, mixed> $headers name => value, as getallheaders() gives them, or name => list of
     *                                         values, as a framework does
     */
    public function handle(string $method, array $headers, string $body): HttpResponse
    {
        try {
            return $this->receive($method, $headers, $body);
        } catch (Throwable) {
            // Neither the message, the class nor the trace of what was thrown may leave the application.
            return $this->refuse(500, Code::InternalError);
        }
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    protected function receive(string $method, array $headers, string $body): HttpResponse
    {
        if (strtoupper($method) !== 'POST') {
            return $this->refuse(405, Code::InvalidRequest);
        }

        $webhookId = $this->header($headers, 'webhook-id');
        $timestamp = $this->header($headers, 'webhook-timestamp');
        $signatures = $this->header($headers, 'webhook-signature');
        $now = time();

        // Nothing is decoded before the signature is verified, over the body as it was received.
        if (!$this->signature->verify($webhookId, $timestamp, $signatures, $body, $now)) {
            // One status and one code for every signature problem: the answer does not say which check failed.
            return $this->refuse(401, Code::InvalidSignature);
        }

        if (strlen($body) > self::LONGEST_BODY) {
            return $this->refuse(400, Code::InvalidRequest);
        }

        $message = PlatformMessage::decode($body, $webhookId, $now);

        return $message instanceof Code ? $this->refuse(400, $message) : $this->answer($message);
    }

    /**
     * @param array<array-key, mixed> $headers
     * @param string $name in lower case
     * @return string the value of the header; empty when the request has none
     */
    protected function header(array $headers, string $name): string
    {
        foreach ($headers as $given => $value) {
            if (strtolower((string) $given) === $name) {
                // A framework gives the values of a repeated header as a list: joined as the platform joins
                // several signatures, so that each of them is tried.
                return implode(' ', array_filter((array) $value, is_string(...)));
            }
        }

        return '';
    }

    protected function answer(PlatformMessage $message): HttpResponse
    {
        if ($message->verb === PlatformMessage::PING) {
            return $this->respond(200, ['protocols' => [Protocol::VERSION]]);
        }

        try {
            $definition = $this->definitionOf($message);
        } catch (CloudException $unknown) {
            return $this->refuse(404, $unknown->reason);
        }

        return match ($message->verb) {
            PlatformMessage::INSPECT => $this->inspect($message, $definition),
            PlatformMessage::ABANDON => $this->abandon($message, $definition),
            default => $this->queue($message),
        };
    }

    /**
     * A start and a wake execute on the version their run ID pins, so this deployment must have it. An
     * inspect and an abandon only read and free the store, which every version of a definition shares.
     *
     * @throws CloudException unknown_definition, unknown_version
     */
    protected function definitionOf(PlatformMessage $message): Definition
    {
        $name = $message->definition;

        return match (true) {
            $message->runsInQueue() => $this->registry->pinned($name, $message->runId),
            $message->runId === null => $this->registry->current($name),
            default => $this->registry->reader($name, $message->runId),
        };
    }

    /**
     * A 202 promises nothing: no workflow is built and the store is not touched. A worker executes the segment.
     */
    protected function queue(PlatformMessage $message): HttpResponse
    {
        $invocation = $message->invocation();

        try {
            $this->dispatcher->dispatch($invocation);
        } catch (Throwable) {
            return $this->refuse(503, Code::DispatchFailed);
        }

        return $this->respond(202, []);
    }

    protected function inspect(PlatformMessage $message, Definition $definition): HttpResponse
    {
        $workflowId = $message->workflowId;

        try {
            $run = $this->runHeldBy($this->builder->build($definition, $workflowId, null));
            $claimed = $message->runId === null ? null : $this->ledger->has($workflowId, $message->runId);
        } catch (Throwable) {
            // An answer whose snapshot was not read shows nothing: the platform asks again.
            return $this->respond(200, $this->snapshot(null, null, snapshotRead: false));
        }

        return $this->respond(200, $this->snapshot($run, $claimed));
    }

    /**
     * The run ID is entered in the ledger first, so that a start still waiting in the queue never runs. A run
     * that holds the workflow ID is then left to the workflow's own abandon(), which keeps the guard of an
     * agent in force. With no run there, an entry this call wrote means the start will never run; one it
     * found there cannot say why, and the platform inspects.
     */
    protected function abandon(PlatformMessage $message, Definition $definition): HttpResponse
    {
        $workflowId = $message->workflowId;
        $runId = $message->runId;

        try {
            $entered = $this->ledger->claim($workflowId, $runId, time() + PlatformMessage::LONGEST_START_LIFETIME) === Claim::Written;
            $workflow = $this->builder->build($definition, $workflowId, null);
            $refusal = $this->refusalOfAbandon($workflow, $runId, $message->attempt, $entered);

            try {
                $run = $this->runHeldBy($workflow);

                if ($run === null && $entered) {
                    // The run that held the workflow ID left while the abandon was dealt with: as when none was found.
                    $refusal = null;
                }

                $answer = $this->snapshot($run, true);
            } catch (Throwable) {
                // The abandon was decided on the control record of the run: the rest of it cannot be read.
                $answer = $this->snapshot(null, true, snapshotRead: false);
            }

            // Released here: a destructor that throws is a failure of this abandon like any other.
            unset($workflow);
        } catch (Throwable) {
            return $this->refuse(503, Code::Unavailable);
        }

        return $refusal === null ? $this->respond(200, $answer) : $this->respond(409, ['code' => $refusal] + $answer);
    }

    /**
     * @param bool $entered whether this abandon wrote the run ID in the ledger
     * @return string|null why the run stays: a RefusalReason value, or abandon_refused; null when it was abandoned,
     *                     and when no run holds the workflow ID and the start of the run ID can never run
     * @throws Throwable when the store cannot be read or written
     */
    protected function refusalOfAbandon(Workflow $workflow, string $runId, ?int $attempt, bool $entered): ?string
    {
        $withoutRun = $entered ? null : RefusalReason::StaleRun->value;

        try {
            $isHeld = $workflow->inspect() instanceof WorkflowRunSnapshot;
            $wasRead = true;
        } catch (Throwable) {
            // A run whose records cannot be read in full still holds the workflow ID. Neuron's abandon() needs
            // only its control record, and says itself when there is none.
            $isHeld = true;
            $wasRead = false;
        }

        if (!$isHeld) {
            return $withoutRun;
        }

        try {
            $workflow->abandon($runId, $attempt);

            return null;
        } catch (StaleWorkflowRunException $stale) {
            // Neuron found no run at all where the first look could not tell.
            return !$wasRead && $stale->actualRunId === null ? $withoutRun : $stale->reason->value;
        } catch (WorkflowRefusedException $refused) {
            return $refused->reason->value;
        } catch (AgentException) {
            // The conversation ends on a tool call nobody answered: the agent keeps its run.
            return Code::AbandonRefused->value;
        }
    }

    /**
     * @return array<string, mixed>|null the run holding the workflow ID now, as the platform may see it
     * @throws Throwable when the store cannot be read
     */
    protected function runHeldBy(Workflow $workflow): ?array
    {
        $snapshot = $workflow->inspect();

        return $snapshot instanceof WorkflowRunSnapshot ? RunProjection::of($snapshot) : null;
    }

    /**
     * The body of the answer to an inspect and to an abandon.
     *
     * @param array<string, mixed>|null $run
     * @param bool|null $claimed whether the run ID the message named is in the ledger; null when it named none
     * @return array<string, mixed>
     */
    protected function snapshot(?array $run, ?bool $claimed, bool $snapshotRead = true): array
    {
        return ['run' => $run, 'claimed' => $claimed, 'snapshotRead' => $snapshotRead, 'at' => time()];
    }

    protected function refuse(int $status, Code $code): HttpResponse
    {
        return $this->respond($status, ['code' => $code->value], $status === 503 ? ['Retry-After' => (string) self::RETRY_AFTER] : []);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function respond(int $status, array $body, array $headers = []): HttpResponse
    {
        // An object, also when the body has no field.
        $json = json_encode((object) $body, JSON_THROW_ON_ERROR);

        return new HttpResponse($status, $json, ['Content-Type' => 'application/json'] + $headers);
    }
}
