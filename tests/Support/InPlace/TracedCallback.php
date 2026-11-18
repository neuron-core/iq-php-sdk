<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\InPlace;

use Closure;
use NeuronAI\Workflow\Observability\WorkflowStart;
use NeuronAI\Workflow\Workflow;

/**
 * A configure() callback that leaves a trace: it hooks a listener onto the
 * workflow it is given, as a streaming endpoint hooks its response, so a test
 * can tell whether the workflow that executed is the one the callback adjusted.
 */
final class TracedCallback
{
    /** @var Workflow[] every workflow the callback was given, in order */
    public array $adjusted = [];

    /** How many segments were executed by a workflow the callback had adjusted. */
    public int $segmentsHeard = 0;

    /**
     * @return Closure(Workflow): void what configure() takes
     */
    public function closure(): Closure
    {
        return function (Workflow $workflow): void {
            $this->adjusted[] = $workflow;

            $workflow->subscribe(WorkflowStart::class, function (): void {
                $this->segmentsHeard++;
            });
        };
    }
}
