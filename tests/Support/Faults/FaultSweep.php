<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Support\Faults;

use Fiber;

use function array_filter;
use function array_map;
use function usleep;

/**
 * One order delivered over a failing store, once for every operation of a
 * clean delivery that the fault can hit.
 */
final class FaultSweep
{
    /**
     * @param FaultCase $clean the same run with no fault and no second copy of any order: what the others are compared with
     * @param FaultCase $unfaulted the drill of the sweep with no fault
     * @param FaultCase[] $faulted the drill once per operation the fault hit
     */
    protected function __construct(
        public readonly FaultCase $clean,
        public readonly FaultCase $unfaulted,
        public readonly array $faulted,
    ) {
    }

    /**
     * @param string $definition one of those FaultDrill registers
     * @param string $origin who issues the start
     * @param int $ordersBefore how many orders of the run are delivered cleanly before the one under test: 0 tests
     *                          the start, 1 the wake or the signal that follows it
     */
    public static function play(string $backend, FaultMode $mode, string $definition, string $origin, int $ordersBefore): self
    {
        $drill = static fn (): FaultDrill => new FaultDrill($backend, $definition, $origin);
        $exposed = self::exposure($drill(), $mode, $ordersBefore);
        $drills = [];

        for ($nth = 1; $nth <= $exposed; $nth++) {
            $drills[] = new Fiber(static fn (): FaultCase => self::drill(
                $drill(),
                $ordersBefore,
                StoreFault::at($mode, $nth),
                "fault {$nth} of {$exposed}",
            ));
        }

        return new self(
            self::clean($drill(), $ordersBefore),
            self::drill($drill(), $ordersBefore, StoreFault::none(), 'no fault'),
            self::together($drills),
        );
    }

    /**
     * @return FaultCase[] every drill of the sweep, the one with no fault first
     */
    public function cases(): array
    {
        return [$this->unfaulted, ...$this->faulted];
    }

    /**
     * The run up to the order under test, that order once, then the wakes
     * the snapshots call for, all over a healthy store.
     */
    protected static function clean(FaultDrill $drill, int $ordersBefore): FaultCase
    {
        try {
            $drill->deliver($drill->orderAfter($ordersBefore));
            $drill->followSnapshots();

            return $drill->conclude('a clean run', StoreFault::none());
        } finally {
            $drill->close();
        }
    }

    /**
     * How many operations of a clean delivery of the order under test a fault of this mode can hit.
     */
    protected static function exposure(FaultDrill $drill, FaultMode $mode, int $ordersBefore): int
    {
        try {
            $order = $drill->orderAfter($ordersBefore);
            $before = $drill->journal->exposedTo($mode);
            $drill->deliver($order);

            return $drill->journal->exposedTo($mode) - $before;
        } finally {
            $drill->close();
        }
    }

    /**
     * Deliver the order over the faulty store, then the same order over a
     * healthy one, then the wakes the snapshots call for. A copy of the start
     * arrives last, when the run has ended or can go no further.
     */
    protected static function drill(FaultDrill $drill, int $ordersBefore, StoreFault $fault, string $label): FaultCase
    {
        try {
            $order = $drill->orderAfter($ordersBefore);
            $drill->deliver($order, $fault);
            $drill->deliver($order);
            $drill->followSnapshots();
            $drill->deliver($drill->start);

            return $drill->conclude($label, $fault);
        } finally {
            $drill->close();
        }
    }

    /**
     * A drill whose fault left the run under a lease waits a second of real
     * time for it to pass: the drills of a sweep wait for theirs together.
     *
     * @param array<int, Fiber<null, null, FaultCase, null>> $drills
     * @return FaultCase[]
     */
    protected static function together(array $drills): array
    {
        foreach ($drills as $drill) {
            $drill->start();
        }

        while (($waiting = array_filter($drills, static fn (Fiber $drill): bool => $drill->isSuspended())) !== []) {
            usleep(FaultDrill::TICK);

            foreach ($waiting as $drill) {
                $drill->resume();
            }
        }

        return array_map(static fn (Fiber $drill): FaultCase => $drill->getReturn(), $drills);
    }
}
