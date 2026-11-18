<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests;

use NeuronCore\IQ\SyncResult;
use PHPUnit\Framework\TestCase;

class SyncResultTest extends TestCase
{
    public function test_it_carries_what_the_platform_answered_to_a_sync(): void
    {
        $result = new SyncResult(true, ['order-fulfilment' => [1]], ['trial' => [3 => 2]]);

        self::assertTrue($result->modified);
        self::assertSame(['order-fulfilment' => [1]], $result->retirable);
        self::assertSame(['trial' => [3 => 2]], $result->stranded);
    }
}
