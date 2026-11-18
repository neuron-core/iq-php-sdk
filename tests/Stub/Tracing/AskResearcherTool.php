<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Tracing;

use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * A tool that runs another agent: the run of that agent starts and ends inside the tool call of the agent that
 * called it.
 */
class AskResearcherTool extends Tool
{
    protected string $name = 'ask_researcher';

    protected ?string $description = 'Ask the researcher.';

    public function __construct(protected Agent $researcher)
    {
    }

    protected function properties(): array
    {
        return [new ToolProperty(name: 'question', type: PropertyType::STRING, description: 'The question.', required: true)];
    }

    public function __invoke(string $question): string
    {
        return (string) $this->researcher->chat(new UserMessage($question))->getMessage()->getContent();
    }
}
