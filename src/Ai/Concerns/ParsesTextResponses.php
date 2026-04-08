<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;

trait ParsesTextResponses
{
    protected function parseTextResponse(array $data, Provider $provider, string $model): TextResponse
    {
        $text = $this->extractText($data['content'] ?? []);
        $usage = $this->extractUsage($data);
        $meta = new Meta($provider->name(), $data['model'] ?? $model);

        return new TextResponse($text, $usage, $meta);
    }

    protected function extractText(array $content): string
    {
        $textBlocks = array_filter($content, fn (array $block) => ($block['type'] ?? '') === 'text');

        return implode('', array_column($textBlocks, 'text'));
    }

    protected function extractUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['input_tokens'] ?? 0,
            $usage['output_tokens'] ?? 0,
            $usage['cache_creation_input_tokens'] ?? 0,
            $usage['cache_read_input_tokens'] ?? 0,
        );
    }
}
