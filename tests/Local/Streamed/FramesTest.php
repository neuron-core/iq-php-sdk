<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Local\Streamed;

use Closure;
use NeuronAI\Exceptions\WorkflowRefusedException;
use NeuronAI\Workflow\RefusalReason;
use NeuronAI\Workflow\Workflow;
use NeuronCore\IQ\Tests\Stub\NodeFailed;
use NeuronCore\IQ\Tests\Stub\Streamed\EchoingAdapter;
use NeuronCore\IQ\Tests\Stub\Streamed\Ending;
use NeuronCore\IQ\Tests\Stub\Streamed\TaleWorkflow;
use NeuronCore\IQ\Tests\Support\Streamed\Kind;
use NeuronCore\IQ\Tests\Support\Streamed\Stage;
use NeuronCore\IQ\Tests\Support\Streamed\StreamedTestCase;
use NeuronCore\IQ\Tests\Support\Streamed\Taken;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_map;
use function count;

/**
 * The stream events() gives is Neuron's own: the consumer receives what a
 * direct call to the events() of an identical workflow gives, frame by frame,
 * and the state or the exception that call ends with. Each test takes the
 * same segment twice: through the SDK, and through Neuron alone on a store of
 * its own, under the same run ID.
 */
class FramesTest extends StreamedTestCase
{
    #[DataProvider('kindsAndEndings')]
    public function test_the_consumer_receives_the_frames_of_neuron_itself_under_the_same_keys(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertEquals($this->takenWithoutTheSdk($stage, $taken)->frames, $taken->frames);
        self::assertGreaterThanOrEqual(3, count($taken->frames), 'The comparison would hold for two empty streams.');
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_frames_come_in_the_order_and_of_the_classes_neuron_yields(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertSame(self::classesOf($this->takenWithoutTheSdk($stage, $taken)), self::classesOf($taken));
    }

    #[DataProvider('kindsAndEndings')]
    public function test_the_consumer_receives_the_frames_the_stream_adapter_gives_neuron(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);
        $stage->thread->configure(self::adapted());

        $taken = $stage->take($stage->stream());

        self::assertEquals($this->takenWithoutTheSdk($stage, $taken, self::adapted())->frames, $taken->frames);
        self::assertGreaterThanOrEqual(5, count($taken->frames), 'The comparison would hold for two empty streams.');
    }

    /**
     * The endpoint of example (b) of the design calls valid() before it answers, then lets the response take the stream.
     */
    #[DataProvider('kinds')]
    public function test_a_consumer_that_called_valid_before_it_takes_the_stream_still_receives_every_frame(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $events = $stage->stream();
        $events->valid();

        $taken = $stage->take($events);

        self::assertSame([...TaleWorkflow::OPENING, ...TaleWorkflow::CLOSING], $taken->words());
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_the_stream_returns_the_state_neuron_returns(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertNotNull($taken->returned);
        self::assertEquals($this->takenWithoutTheSdk($stage, $taken)->returned, $taken->returned);
    }

    #[DataProvider('kindsThatReturnAState')]
    public function test_the_state_the_stream_returns_ended_as_its_last_node_ended_it(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertSame(
            [self::outcomeOf($ending)->value, $kind->attempt()],
            [$taken->returned?->getStatus()->value, $taken->returned?->getExecutionAttempt()],
        );
    }

    #[DataProvider('kindsThatThrow')]
    public function test_the_stream_throws_what_neuron_throws_once_its_frames_were_given(string $backend, Kind $kind, Ending $ending): void
    {
        $stage = $this->stage($backend, $kind, $ending);

        $taken = $stage->take($stage->stream());

        self::assertNotNull($taken->thrown);
        self::assertEquals($this->takenWithoutTheSdk($stage, $taken)->thrown, $taken->thrown);
    }

    #[DataProvider('kinds')]
    public function test_the_stream_throws_the_very_exception_a_node_threw(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $failure = new NodeFailed('The node failed between two words.');
        $stage->midStream->whenReached(static fn () => throw $failure);

        $taken = $stage->take($stage->stream());

        self::assertSame($failure, $taken->thrown);
    }

    #[DataProvider('kinds')]
    public function test_the_stream_throws_the_very_refusal_that_escaped_from_a_node(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind);
        $refusal = new WorkflowRefusedException('A workflow this node drives is executing.', RefusalReason::Executing);
        $stage->midStream->whenReached(static fn () => throw $refusal);

        $taken = $stage->take($stage->stream());

        self::assertSame($refusal, $taken->thrown);
    }

    #[DataProvider('kinds')]
    public function test_a_stream_that_threw_is_over(string $backend, Kind $kind): void
    {
        $stage = $this->stage($backend, $kind, Ending::Fails);
        $events = $stage->stream();

        $stage->take($events);

        self::assertFalse($events->valid());
    }

    /**
     * @return Closure(Workflow): Workflow what a streaming endpoint gives configure()
     */
    protected static function adapted(): Closure
    {
        return static fn (Workflow $workflow): Workflow => $workflow->setStreamAdapter(static fn (): EchoingAdapter => new EchoingAdapter());
    }

    /**
     * The segment that was just streamed through the SDK, taken through Neuron alone.
     *
     * @param (Closure(Workflow): mixed)|null $configure
     */
    protected function takenWithoutTheSdk(Stage $stage, Taken $throughTheSdk, ?Closure $configure = null): Taken
    {
        return Taken::from($stage->withoutTheSdk($this->apart(), self::runIdOf($stage, $throughTheSdk), $configure));
    }

    /**
     * @return array<int, array{int|string, class-string}> the key and the class of every frame, in order
     */
    protected static function classesOf(Taken $taken): array
    {
        return array_map(static fn (array $frame): array => [$frame[0], $frame[1]::class], $taken->frames);
    }
}
