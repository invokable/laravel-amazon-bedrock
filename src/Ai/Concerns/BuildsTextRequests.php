<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
    ): array {
        $config = $provider->additionalConfiguration();
        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        $anthropicVersion = $providerOptions['anthropic_version']
            ?? $config['anthropic_version']
            ?? 'bedrock-2023-05-31';

        unset($providerOptions['anthropic_version']);

        $body = [
            'anthropic_version' => $anthropicVersion,
            'max_tokens' => $options?->maxTokens ?? (int) ($config['max_tokens'] ?? 8096),
            'messages' => $this->mapMessages($messages),
        ];

        if (filled($instructions)) {
            $body['system'] = $this->buildSystemPrompt($instructions);
        }

        $mappedTools = filled($tools) ? $this->mapTools($tools) : [];

        if (filled($schema)) {
            $mappedTools[] = $this->buildStructuredOutputTool($schema);
        }

        if (filled($mappedTools)) {
            $body['tools'] = $mappedTools;
            $body['tool_choice'] = $this->resolveToolChoice($schema, $tools, $providerOptions);
        }

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        return array_merge($body, $providerOptions);
    }

    /**
     * Determine the tool_choice strategy for the request.
     *
     * When only structured output is requested, force the synthetic tool.
     * When both real tools and schema are present, use "any" so the model can call either.
     */
    protected function resolveToolChoice(?array $schema, array $tools, array $providerOptions): array
    {
        if (! filled($schema)) {
            return ['type' => 'auto'];
        }

        return filled($tools)
            ? ['type' => 'any']
            : ['type' => 'tool', 'name' => 'output_structured_data'];
    }

    /**
     * Build the synthetic tool definition for structured output.
     */
    protected function buildStructuredOutputTool(array $schema): array
    {
        $schemaArray = (new ObjectSchema($schema))->toSchema();

        return [
            'name' => 'output_structured_data',
            'description' => 'Output the structured data matching the required schema.',
            'input_schema' => [
                'type' => 'object',
                'properties' => (object) ($schemaArray['properties'] ?? []),
                'required' => $schemaArray['required'] ?? [],
            ],
        ];
    }

    /**
     * Build system prompt with ephemeral cache_control.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildSystemPrompt(string $instructions): array
    {
        return [
            [
                'type' => 'text',
                'text' => $instructions,
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ];
    }
}
