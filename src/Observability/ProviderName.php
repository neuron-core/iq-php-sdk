<?php

declare(strict_types=1);

namespace NeuronCore\Cloud\Observability;

use function strrchr;
use function strstr;
use function strtolower;
use function substr;

/**
 * The conventions' `gen_ai.provider.name` of a provider class, which is the name
 * the platform prices a model under: a well-known value where one exists, the
 * lower-cased class name otherwise (custom values are allowed).
 */
class ProviderName
{
    /**
     * Framework provider classes whose lower-cased short name is not the
     * conventions' `gen_ai.provider.name` value. Every other class name already is.
     */
    protected const NAMES = [
        'gemini' => 'gcp.gemini',
        'geminivertex' => 'gcp.vertex_ai',
        'anthropicvertex' => 'gcp.vertex_ai',
        'azureopenai' => 'azure.ai.openai',
        'openairesponses' => 'openai',
        'bedrockruntime' => 'aws.bedrock',
        'mistral' => 'mistral_ai',
        'grok' => 'x_ai',
    ];

    /**
     * An anonymous class is named after its parent, and the rest of its class
     * name is a NUL byte and the path of its file, neither of which leaves here.
     */
    public static function of(string $class): string
    {
        $named = strstr($class, '@anonymous', true);
        $class = $named === false ? $class : $named;
        $separator = strrchr($class, '\\');
        $id = strtolower($separator === false ? $class : substr($separator, 1));

        return self::NAMES[$id] ?? $id;
    }
}
