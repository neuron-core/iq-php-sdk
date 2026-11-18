<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Flows;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronCore\IQ\Tests\Support\ExecutionLog;

/**
 * Moves money, so every call of it waits for a person.
 */
class RefundTool extends Tool
{
    protected string $name = 'refund';

    protected ?string $description = 'Refund an order to a bank account.';

    public function __construct(protected ExecutionLog $executions)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'iban', type: PropertyType::STRING, description: 'The account to refund.', required: true),
        ];
    }

    protected function approvalPolicy(): bool
    {
        return true;
    }

    public function __invoke(string $iban): string
    {
        $this->executions->record(self::class);

        return "Refunded to {$iban}.";
    }
}
