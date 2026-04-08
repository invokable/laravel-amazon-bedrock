<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

trait BuildsTextRequests
{
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        ?TextGenerationOptions $options,
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

        if ($options?->temperature !== null) {
            $body['temperature'] = $options->temperature;
        }

        return array_merge($body, $providerOptions);
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
