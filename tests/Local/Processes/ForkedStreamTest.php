<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Processes;

use NeuronCore\IQ\Tests\Stub\AwaitPaymentNode;
use NeuronCore\IQ\Tests\Stub\NarrateNode;
use NeuronCore\IQ\Tests\Support\IQTestCase;
use NeuronCore\IQ\Tests\Support\Processes\ScriptProcess;
use NeuronCore\IQ\Tests\Support\TestBench;

use function extension_loaded;

use const PHP_BINARY;

/**
 * A process that forks while it streams a segment. The child holds a copy of
 * the stream, and PHP releases that copy when the child ends. Neuron settles
 * nothing in a process that did not admit the segment, and the SDK hands
 * nothing to the queue there: only the process that took the first step of
 * the stream tells how it ended. Each case is a real process and its child.
 */
class ForkedStreamTest extends IQTestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('pcntl')) {
            self::markTestSkipped('Forking requires the pcntl extension.');
        }
    }

    public function test_a_child_forked_between_two_frames_hands_nothing_and_the_parent_hands_the_one_observe_of_the_stream_it_ends(): void
    {
        $process = $this->forkedWhileStreaming('between-two-frames');

        self::assertSame("parent: announce -\nparent: observe completed\n", $process->printed);
        self::assertSame(1, $this->bench->executions->count(NarrateNode::class));
    }

    public function test_a_child_forked_on_the_last_event_of_a_segment_that_suspended_hands_nothing(): void
    {
        $process = $this->forkedWhileStreaming('on-the-event-of-the-wait');

        self::assertSame("parent: announce -\nparent: observe suspended\n", $process->printed);
        self::assertSame(1, $this->bench->executions->count(AwaitPaymentNode::class));
    }

    public function test_a_child_that_takes_a_stream_its_parent_prepared_hands_the_one_observe_and_the_parent_hands_none(): void
    {
        $process = $this->forkedWhileStreaming('before-the-first-frame');

        self::assertSame("parent: announce -\nchild: observe completed\n", $process->printed);
        self::assertSame(1, $this->bench->executions->count(NarrateNode::class));
    }

    /**
     * @param string $where where the script forks: see fork-during-a-stream.php
     */
    protected function forkedWhileStreaming(string $where): ScriptProcess
    {
        $bench = $this->benchOn(TestBench::IN_MEMORY);

        $process = ScriptProcess::run(PHP_BINARY, 'fork-during-a-stream.php', $bench->executions->path, $where);

        self::assertSame([0, null], [$process->exitCode, $process->signal], 'The script did not end normally: ' . $process->printed);

        return $process;
    }
}
