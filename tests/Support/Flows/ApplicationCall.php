<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support\Flows;

use NeuronAI\Exceptions\RunInFlightException;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\WorkflowState;
use NeuronCore\Cloud\CloudException;
use NeuronCore\Cloud\Invocation;
use NeuronCore\Cloud\NeuronCloud;
use NeuronCore\Cloud\Tests\Stub\Flows\OrderPlaced;
use Throwable;

/**
 * What one process of an application does with its NeuronCloud, in a form
 * that travels to that process and back: the call, then what it returned or
 * what it threw.
 */
final class ApplicationCall
{
    protected const START_AND_RUN = 'start-and-run';

    protected const ANSWER_AND_RUN = 'answer-and-run';

    protected const INVOKE = 'invoke';

    /**
     * A request that calls start(new OrderPlaced($orderId))->run().
     *
     * @return array<string, mixed>
     */
    public static function startAndRun(string $definition, string $workflowId, string $orderId): array
    {
        return ['call' => self::START_AND_RUN, 'definition' => $definition, 'workflowId' => $workflowId, 'orderId' => $orderId];
    }

    /**
     * A request that calls submitInputs($payload)->run().
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function answerAndRun(string $definition, string $workflowId, array $payload): array
    {
        return ['call' => self::ANSWER_AND_RUN, 'definition' => $definition, 'workflowId' => $workflowId, 'payload' => $payload];
    }

    /**
     * A queue worker that gives one message to invoke().
     *
     * @return array<string, mixed>
     */
    public static function invoke(Invocation $invocation): array
    {
        return ['call' => self::INVOKE, 'invocation' => $invocation->toArray()];
    }

    /**
     * @param array<string, mixed> $call
     * @return array<string, mixed> "returned": the state a local call returned; "reported": the report invoke()
     *                              returned; "thrown": the class and the reason of what either threw
     */
    public static function perform(NeuronCloud $cloud, array $call): array
    {
        try {
            return match ($call['call']) {
                self::START_AND_RUN => self::returned(
                    $cloud->workflow($call['definition'], $call['workflowId'])->start(new OrderPlaced($call['orderId']))->run(),
                ),
                self::ANSWER_AND_RUN => self::returned(
                    $cloud->workflow($call['definition'], $call['workflowId'])->submitInputs($call['payload'])->run(),
                ),
                default => ['reported' => $cloud->invoke(Invocation::fromArray($call['invocation']))->toArray()],
            };
        } catch (Throwable $thrown) {
            return ['thrown' => [
                'class' => $thrown::class,
                'reason' => self::reasonOf($thrown),
                'holder' => $thrown instanceof RunInFlightException ? $thrown->runId : null,
            ]];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function returned(WorkflowState $state): array
    {
        return ['returned' => [
            'status' => $state->getStatus()->value,
            'runId' => $state->getRunId(),
            'orderId' => $state->get('orderId'),
            'payment' => $state->get('payment'),
        ]];
    }

    protected static function reasonOf(Throwable $thrown): ?string
    {
        return match (true) {
            $thrown instanceof WorkflowRefusedException => $thrown->reason->value,
            $thrown instanceof CloudException => $thrown->reason->value,
            default => null,
        };
    }
}
