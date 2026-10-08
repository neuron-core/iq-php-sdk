<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A test that runs on a TestBench, once per backend with the "backends" data
 * provider. The bench is cleaned up when the test ends.
 */
abstract class CloudTestCase extends TestCase
{
    protected TestBench $bench;

    protected function tearDown(): void
    {
        if (isset($this->bench)) {
            $this->bench->cleanUp();
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function backends(): array
    {
        return TestBench::backends();
    }

    protected function benchOn(string $backend): TestBench
    {
        return $this->bench = TestBench::on($backend);
    }
}
