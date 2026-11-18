<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

/**
 * One failure of a store, at the Nth operation it receives: every operation
 * before and after it is served.
 */
final class StoreFault
{
    protected int $counted = 0;

    protected ?StoreOperation $struck = null;

    protected function __construct(protected ?FaultMode $mode, protected int $nth)
    {
    }

    public static function none(): self
    {
        return new self(null, 0);
    }

    /**
     * @param int $nth counted over every operation for a fault before an operation, over the writes only for one after a write
     */
    public static function at(FaultMode $mode, int $nth): self
    {
        return new self($mode, $nth);
    }

    public function strikesBefore(StoreOperation $operation): bool
    {
        return $this->mode === FaultMode::BeforeOperation && $this->strikes($operation);
    }

    public function strikesAfter(StoreOperation $operation): bool
    {
        return $this->mode === FaultMode::AfterWrite && $operation->writes() && $this->strikes($operation);
    }

    /**
     * The operation the fault hit, or null while it has hit none.
     */
    public function struck(): ?StoreOperation
    {
        return $this->struck;
    }

    /**
     * Whether the store did what the operation it failed asked for.
     */
    public function committed(): bool
    {
        return $this->mode === FaultMode::AfterWrite && $this->struck instanceof StoreOperation;
    }

    protected function strikes(StoreOperation $operation): bool
    {
        if (++$this->counted !== $this->nth) {
            return false;
        }

        $this->struck = $operation;

        return true;
    }
}
