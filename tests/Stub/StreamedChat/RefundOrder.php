<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\StreamedChat;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCore\Cloud\Tests\Support\ExecutionLog;
use NeuronCore\Cloud\Tests\Support\StreamedChat\Confidential;

/**
 * Moves money, so every call of it waits for a person. It counts its
 * executions in the log, the only memory that survives a failed run.
 */
class RefundOrder extends Tool
{
    protected string $name = 'refund_order';

    protected ?string $description = 'Refund an order of the customer.';

    /**
     * @param int $failures how many executions throw before one refunds the order
     */
    public function __construct(protected ExecutionLog $executions, protected int $failures = 0)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'order', type: PropertyType::STRING, description: 'The order to refund.', required: true),
            new ToolProperty(name: 'note', type: PropertyType::STRING, description: 'Why it is refunded.', required: false),
        ];
    }

    protected function approvalPolicy(): bool
    {
        return true;
    }

    public function __invoke(string $order, string $note = ''): string
    {
        $this->executions->record(self::class);

        if ($this->executions->count(self::class) <= $this->failures) {
            throw new PaymentProviderDown('The payment provider is down. ' . Confidential::EXCEPTION);
        }

        return "Order {$order} was refunded. " . Confidential::TOOL_RESULT;
    }
}
