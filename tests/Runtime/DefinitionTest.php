<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Runtime;

use NeuronAI\Workflow\Events\StartEvent;
use NeuronAI\Workflow\Workflow;
use NeuronAI\Workflow\WorkflowRunSnapshot;
use NeuronAI\Workflow\WorkflowStatus;
use NeuronCore\IQ\Runtime\Definition;
use PHPUnit\Framework\TestCase;

/**
 * A run carries the name of the definition that started it as its tag, and
 * only that definition may act on it.
 */
class DefinitionTest extends TestCase
{
    public function test_a_definition_may_act_on_a_run_that_carries_its_name_as_tag(): void
    {
        self::assertTrue(self::definition('order-fulfilment')->mayActOn(self::runTagged('order-fulfilment')));
    }

    public function test_a_definition_may_not_act_on_a_run_that_carries_the_name_of_another_definition(): void
    {
        self::assertFalse(self::definition('order-fulfilment')->mayActOn(self::runTagged('support-agent')));
    }

    public function test_a_definition_may_not_act_on_a_run_whose_tag_only_begins_with_its_name(): void
    {
        self::assertFalse(self::definition('order')->mayActOn(self::runTagged('order-fulfilment')));
    }

    public function test_every_version_of_a_definition_may_act_on_a_run_its_name_started(): void
    {
        self::assertTrue(self::definition('order-fulfilment', version: 7)->mayActOn(self::runTagged('order-fulfilment')));
    }

    public function test_a_run_that_carries_no_tag_is_not_checked(): void
    {
        self::assertTrue(self::definition('order-fulfilment')->mayActOn(self::runTagged(null)));
    }

    public function test_a_definition_may_act_where_no_run_holds_the_workflow_id(): void
    {
        self::assertTrue(self::definition('order-fulfilment')->mayActOn(null));
    }

    protected static function definition(string $name, int $version = 1): Definition
    {
        return new Definition($name, $version, static fn (): Workflow => new Workflow(), 600);
    }

    protected static function runTagged(?string $tag): WorkflowRunSnapshot
    {
        return new WorkflowRunSnapshot('1-0192f3a8', WorkflowStatus::Suspended, 1, null, 'order:123', new StartEvent(), tag: $tag);
    }
}
