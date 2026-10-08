<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Stub\Local;

use NeuronAI\Exceptions\InputTranslationException;
use NeuronAI\Workflow\Interrupt\InputTranslatorInterface;
use NeuronAI\Workflow\Interrupt\InterruptRequest;

use function is_numeric;

/**
 * Turns the body of a payment webhook into the answer the workflow reads,
 * and remembers the wait it translated against.
 */
class PaidAmountTranslator implements InputTranslatorInterface
{
    public ?InterruptRequest $translatedAgainst = null;

    public function translate(array $payload, InterruptRequest $request): array
    {
        $this->translatedAgainst = $request;

        if (!is_numeric($payload['paid'] ?? null)) {
            throw new InputTranslationException('The webhook names no paid amount.');
        }

        return ['amount' => (int) $payload['paid']];
    }
}
