<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Tests\Observability;

use NeuronCore\Cloud\Observability\Trace;
use NeuronCore\Cloud\Tests\Stub\NodeFailed;
use NeuronCore\Cloud\Tests\Support\Tracing\Otlp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_intersect_key;
use function array_keys;
use function hash;
use function strlen;
use function substr;

/**
 * The trace of one segment while it is built, and the OTLP export request it
 * closes into.
 */
class TraceTest extends TestCase
{
    protected const RUN_ID = '2-0192f3a8-7c1e-7d3a-9b1c-2f4e6a8c0d12';

    public function test_the_trace_id_is_derived_from_the_run_id_so_every_segment_of_a_run_lands_in_one_trace(): void
    {
        $first = self::exportOf(self::identified());
        $second = self::exportOf(self::identified());

        self::assertSame(substr(hash('sha256', self::RUN_ID), 0, 32), Otlp::spans($first)[0]['traceId']);
        self::assertSame(Otlp::spans($first)[0]['traceId'], Otlp::spans($second)[0]['traceId']);
    }

    public function test_two_segments_of_a_run_never_share_a_span_id(): void
    {
        $first = Otlp::spans(self::exportOf(self::identified()))[0];
        $second = Otlp::spans(self::exportOf(self::identified()))[0];

        self::assertSame(16, strlen($first['spanId']));
        self::assertNotSame($first['spanId'], $second['spanId']);
    }

    public function test_a_trace_that_was_given_no_run_id_keeps_a_random_trace_id(): void
    {
        $first = Otlp::spans(self::exportOf(new Trace()))[0]['traceId'];
        $second = Otlp::spans(self::exportOf(new Trace()))[0]['traceId'];

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $first);
        self::assertNotSame($first, $second);
    }

    public function test_a_span_opened_inside_another_names_it_as_its_parent_and_the_outermost_has_none(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->openSpan('node', 'ChargeNode');
        $trace->closeSpan('node');

        $export = $trace->close();

        self::assertSame(['ChargeNode', 'invoke_workflow order-fulfilment'], Otlp::names($export));
        self::assertSame(Otlp::span($export, 'invoke_workflow order-fulfilment')['spanId'], Otlp::span($export, 'ChargeNode')['parentSpanId']);
        self::assertArrayNotHasKey('parentSpanId', Otlp::span($export, 'invoke_workflow order-fulfilment'));
    }

    public function test_a_span_whose_work_runs_in_another_process_takes_no_child(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_agent support');
        $trace->openSpan('tool:1', 'execute_tool refund', forked: true);
        $trace->openSpan('tool:2', 'execute_tool notify', forked: true);

        $export = $trace->close();

        $root = Otlp::span($export, 'invoke_agent support')['spanId'];
        self::assertSame(
            [$root, $root],
            [Otlp::span($export, 'execute_tool refund')['parentSpanId'], Otlp::span($export, 'execute_tool notify')['parentSpanId']],
        );
    }

    public function test_closing_the_trace_closes_the_spans_still_open_innermost_first(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->openSpan('node', 'ChargeNode');

        $spans = Otlp::spans($trace->close());

        self::assertSame(['ChargeNode', 'invoke_workflow order-fulfilment'], array_column($spans, 'name'));
        foreach ($spans as $span) {
            self::assertGreaterThanOrEqual((int) $span['startTimeUnixNano'], (int) $span['endTimeUnixNano']);
        }
    }

    public function test_a_key_opened_twice_closes_the_span_whose_end_was_lost(): void
    {
        $trace = self::identified();
        $trace->openSpan('node', 'ChargeNode');
        $trace->openSpan('node', 'ChargeNode');

        self::assertSame(['ChargeNode', 'ChargeNode'], Otlp::names($trace->close()));
    }

    public function test_closing_a_span_that_is_not_open_changes_nothing(): void
    {
        $trace = self::identified();
        $trace->closeSpan('node');

        self::assertSame([], Otlp::spans($trace->close()));
    }

    public function test_a_trace_is_empty_until_a_span_is_opened_and_stays_not_empty_once_that_span_is_closed(): void
    {
        $trace = self::identified();
        $trace->recordError(new NodeFailed('Recorded while no span was open.'));
        $beforeAnySpan = $trace->isEmpty();
        $trace->openSpan('node', 'ChargeNode');
        $whileASpanIsOpen = $trace->isEmpty();
        $trace->closeSpan('node');

        self::assertSame([true, false, false], [$beforeAnySpan, $whileASpanIsOpen, $trace->isEmpty()]);
    }

    public function test_attributes_given_when_a_span_closes_are_added_to_those_it_opened_with(): void
    {
        $trace = self::identified();
        $trace->openSpan('inference', 'chat fake', Trace::KIND_CLIENT, ['gen_ai.request.model' => 'fake']);
        $trace->closeSpan('inference', ['gen_ai.usage.output_tokens' => 12]);

        $span = Otlp::span($trace->close(), 'chat fake');

        self::assertSame(Trace::KIND_CLIENT, $span['kind']);
        self::assertSame(['fake', 12], [$span['attributes']['gen_ai.request.model'], $span['attributes']['gen_ai.usage.output_tokens']]);
    }

    public function test_a_span_closed_with_an_error_carries_an_error_status_and_does_not_fail_the_run(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->openSpan('validate', 'structured.validate');
        $trace->closeSpan('validate', error: 'Structured output validation failed');

        $export = $trace->close();

        self::assertSame(['code' => 2, 'message' => 'Structured output validation failed'], Otlp::span($export, 'structured.validate')['status']);
        self::assertSame('completed', Otlp::span($export, 'invoke_workflow order-fulfilment')['attributes']['neuron.run.status']);
    }

    public function test_the_status_of_the_run_is_completed_unless_it_is_marked_and_rides_on_the_parentless_span_alone(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->openSpan('node', 'ChargeNode');

        $export = $trace->close();

        self::assertSame('completed', Otlp::span($export, 'invoke_workflow order-fulfilment')['attributes']['neuron.run.status']);
        self::assertArrayNotHasKey('neuron.run.status', Otlp::span($export, 'ChargeNode')['attributes']);
    }

    public function test_a_run_marked_suspended_says_so(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->markSuspended();

        self::assertSame('suspended', Otlp::spans($trace->close())[0]['attributes']['neuron.run.status']);
    }

    public function test_a_run_marked_failed_fails_the_innermost_open_span_with_the_last_error_it_recorded(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_agent support');
        $trace->openSpan('tool', 'execute_tool refund');
        $trace->recordError(new RuntimeException('An earlier error the run survived.'));
        $trace->recordError(new NodeFailed('The payment provider is down.'));
        $trace->markFailed();

        $export = $trace->close();

        $tool = Otlp::span($export, 'execute_tool refund');
        self::assertSame(['code' => 2, 'message' => 'The payment provider is down.'], $tool['status']);
        self::assertSame(NodeFailed::class, $tool['attributes']['error.type']);
        self::assertSame('failed', Otlp::span($export, 'invoke_agent support')['attributes']['neuron.run.status']);
        self::assertArrayNotHasKey('status', Otlp::span($export, 'invoke_agent support'));
    }

    public function test_an_error_that_is_recorded_is_an_exception_event_and_fails_neither_the_span_nor_the_run(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->recordError(new NodeFailed('The audit log is unreachable.'));

        $span = Otlp::spans($trace->close())[0];

        self::assertArrayNotHasKey('status', $span);
        self::assertArrayNotHasKey('error.type', $span['attributes']);
        self::assertSame('completed', $span['attributes']['neuron.run.status']);
        self::assertSame(['exception'], array_column($span['events'], 'name'));
        self::assertSame(
            [NodeFailed::class, 'The audit log is unreachable.'],
            [$span['events'][0]['attributes']['exception.type'], $span['events'][0]['attributes']['exception.message']],
        );
        self::assertStringContainsString(__FUNCTION__, $span['events'][0]['attributes']['exception.stacktrace']);
    }

    public function test_a_run_marked_failed_that_recorded_no_error_still_fails_its_span_with_no_message_and_no_type(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->markFailed();

        $span = Otlp::spans($trace->close())[0];

        self::assertSame(['code' => 2, 'message' => ''], $span['status']);
        self::assertArrayNotHasKey('error.type', $span['attributes']);
        self::assertSame('failed', $span['attributes']['neuron.run.status']);
    }

    public function test_a_failed_run_stays_failed_when_it_is_marked_suspended_afterwards(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->markFailed();
        $trace->markSuspended();

        self::assertSame('failed', Otlp::spans($trace->close())[0]['attributes']['neuron.run.status']);
    }

    public function test_an_exception_recorded_while_no_span_is_open_is_dropped(): void
    {
        $trace = self::identified();
        $trace->recordException(new RuntimeException('The channel is closed.'));
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');

        self::assertSame([], Otlp::spans($trace->close())[0]['events']);
    }

    public function test_every_span_carries_the_run_id_the_workflow_id_and_the_attributes_of_the_trace(): void
    {
        $trace = new Trace(null, ['deployment.environment' => 'production', 'user.id' => null]);
        $trace->identify(self::RUN_ID, 'order:123');
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');
        $trace->openSpan('node', 'ChargeNode');

        foreach (Otlp::spans($trace->close()) as $span) {
            self::assertSame(
                [self::RUN_ID, 'order:123', 'production'],
                [$span['attributes']['neuron.run.id'], $span['attributes']['neuron.workflow.id'], $span['attributes']['deployment.environment']],
            );
            self::assertArrayNotHasKey('user.id', $span['attributes']);
        }
    }

    public function test_the_resource_names_the_service_and_the_sdk_with_its_version(): void
    {
        $named = Otlp::resource((new Trace('checkout'))->close());
        $unnamed = Otlp::resource((new Trace())->close());

        self::assertSame(
            ['service.name' => 'checkout', 'telemetry.sdk.name' => 'neuron-cloud-php', 'telemetry.sdk.language' => 'php', 'telemetry.sdk.version' => '1.0.0'],
            $named,
        );
        self::assertSame('unknown_service', $unnamed['service.name']);
    }

    public function test_the_export_names_its_scope_with_the_version_of_the_sdk(): void
    {
        $export = self::identified()->close();

        self::assertSame(['name' => 'neuron-core/cloud-sdk', 'version' => '1.0.0'], $export['resourceSpans'][0]['scopeSpans'][0]['scope']);
    }

    public function test_attributes_are_typed_as_otlp_types_them_and_a_null_is_left_out(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment', attributes: [
            'cached' => true,
            'tokens' => 12,
            'score' => 0.5,
            'reasons' => ['stop', 'length'],
            'usage' => ['input' => 3],
            'model' => 'fake',
            'missing' => null,
        ]);

        $encoded = [];
        foreach ($trace->close()['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] as ['key' => $key, 'value' => $value]) {
            $encoded[$key] = $value;
        }

        self::assertSame(
            [
                'cached' => ['boolValue' => true],
                'tokens' => ['intValue' => '12'],
                'score' => ['doubleValue' => 0.5],
                'reasons' => ['arrayValue' => ['values' => [['stringValue' => 'stop'], ['stringValue' => 'length']]]],
                'usage' => ['kvlistValue' => ['values' => [['key' => 'input', 'value' => ['intValue' => '3']]]]],
                'model' => ['stringValue' => 'fake'],
            ],
            array_intersect_key($encoded, ['cached' => 1, 'tokens' => 1, 'score' => 1, 'reasons' => 1, 'usage' => 1, 'model' => 1]),
        );
        self::assertNotContains('missing', array_keys($encoded));
    }

    public function test_times_are_unix_nanoseconds_as_decimal_strings(): void
    {
        $trace = self::identified();
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');

        $span = Otlp::spans($trace->close())[0];

        self::assertMatchesRegularExpression('/^\d{19}$/', $span['startTimeUnixNano']);
        self::assertMatchesRegularExpression('/^\d{19}$/', $span['endTimeUnixNano']);
    }

    protected static function identified(): Trace
    {
        $trace = new Trace();
        $trace->identify(self::RUN_ID, 'order:123');

        return $trace;
    }

    /**
     * The export of a trace that has one span.
     *
     * @return array<string, mixed>
     */
    protected static function exportOf(Trace $trace): array
    {
        $trace->openSpan('workflow', 'invoke_workflow order-fulfilment');

        return $trace->close();
    }
}
