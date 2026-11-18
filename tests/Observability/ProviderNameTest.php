<?php

declare(strict_types=1);

namespace NeuronCore\IQ\Tests\Observability;

use NeuronAI\Testing\FakeAIProvider;
use NeuronCore\IQ\Observability\ProviderName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProviderNameTest extends TestCase
{
    #[DataProvider('classes')]
    public function test_a_provider_class_gets_the_name_the_platform_prices_it_under(string $class, string $name): void
    {
        self::assertSame($name, ProviderName::of($class));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function classes(): array
    {
        return [
            'a provider with a well-known name' => [\NeuronAI\Providers\Gemini\Gemini::class, 'gcp.gemini'],
            'gemini on vertex' => [\NeuronAI\Providers\Gemini\GeminiVertex::class, 'gcp.vertex_ai'],
            'anthropic on vertex' => [\NeuronAI\Providers\Anthropic\AnthropicVertex::class, 'gcp.vertex_ai'],
            'azure' => [\NeuronAI\Providers\OpenAI\AzureOpenAI::class, 'azure.ai.openai'],
            'the responses api' => [\NeuronAI\Providers\OpenAI\Responses\OpenAIResponses::class, 'openai'],
            'bedrock' => [\NeuronAI\Providers\AWS\BedrockRuntime::class, 'aws.bedrock'],
            'mistral' => [\NeuronAI\Providers\Mistral\Mistral::class, 'mistral_ai'],
            'grok' => [\NeuronAI\Providers\XAI\Grok::class, 'x_ai'],
            'a provider named after its class' => [\NeuronAI\Providers\Anthropic\Anthropic::class, 'anthropic'],
            'a class with no namespace' => ['LocalModel', 'localmodel'],
        ];
    }

    public function test_an_anonymous_provider_is_named_after_its_parent_and_keeps_its_file_to_itself(): void
    {
        $provider = new class () extends FakeAIProvider {
        };

        self::assertSame('fakeaiprovider', ProviderName::of($provider::class));
    }

    public function test_an_anonymous_class_with_no_parent_has_a_clean_name(): void
    {
        $name = ProviderName::of((new class () {
        })::class);

        self::assertSame('class', $name);
    }
}
