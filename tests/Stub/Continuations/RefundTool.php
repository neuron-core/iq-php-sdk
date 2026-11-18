<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Continuations;

use NeuronAI\Tools\Tool;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * A tool with a side effect a person must approve: it counts its executions
 * in the log, as the nodes of the stub workflows do.
 */
class RefundTool extends Tool
{
    public const RESULT = 'The order was refunded.';

    protected string $name = 'refund_order';

    protected ?string $description = 'Refund the order of the customer.';

    /**
     * @param int $failures how many executions throw before one refunds the order
     */
    public function __construct(protected ExecutionLog $executions, protected int $failures = 0)
    {
    }

    public function __invoke(): string
    {
        $this->executions->record(self::class);

        // The log is the only memory that survives a failed run, and another process.
        if ($this->executions->count(self::class) <= $this->failures) {
            throw new RefundFailed('The payment provider is down.');
        }

        return self::RESULT;
    }
}
