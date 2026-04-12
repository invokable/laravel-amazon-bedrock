<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

trait DetectsModelApi
{
    /**
     * Determine whether the model should use the Converse API.
     *
     * Non-Anthropic models (Amazon Nova, Meta Llama, Mistral, Cohere, AI21, DeepSeek, etc.)
     * use the Converse API which provides a unified interface across all Bedrock models.
     * Anthropic models continue to use the native Anthropic Messages API for full
     * feature compatibility (cache_control, anthropic_version, etc.).
     */
    protected function useConverseApi(string $model): bool
    {
        return ! $this->isAnthropicModel($model);
    }

    /**
     * Check if the model is an Anthropic Claude model.
     */
    protected function isAnthropicModel(string $model): bool
    {
        return str_contains($model, 'anthropic.') || str_contains($model, 'anthropic/');
    }

    protected function converseUrl(string $model): string
    {
        return "model/{$model}/converse";
    }

    protected function converseStreamUrl(string $model): string
    {
        return "model/{$model}/converse-stream";
    }
}
