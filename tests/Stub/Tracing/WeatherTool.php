<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Stub\Tracing;

use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;

/**
 * A tool an agent calls at once, with an argument and a result a trace may or may not carry.
 */
class WeatherTool extends Tool
{
    protected string $name = 'weather';

    protected ?string $description = 'The weather of a city.';

    protected function properties(): array
    {
        return [new ToolProperty(name: 'city', type: PropertyType::STRING, description: 'The city.', required: true)];
    }

    public function __invoke(string $city): string
    {
        return "Sunny in {$city}";
    }
}
