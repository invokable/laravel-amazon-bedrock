<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;

trait ParsesConverseResponses
{
    /**
     * Parse a single Converse response into a step response.
     */
    protected function parseConverseTextStep(array $result, Provider|TextProvider $provider, string $model, bool $structured): StepResponse
    {
        $usage = new Usage(
            promptTokens: $result['usage']['inputTokens'] ?? 0,
            completionTokens: $result['usage']['outputTokens'] ?? 0,
            cacheWriteInputTokens: $result['usage']['cacheWriteInputTokens'] ?? 0,
            cacheReadInputTokens: $result['usage']['cacheReadInputTokens'] ?? 0,
        );

        $output = '';
        $toolCalls = [];
        $providerContentBlocks = [];
        $structuredOutput = null;

        foreach ($result['output']['message']['content'] ?? [] as $block) {
            $providerContentBlocks[] = $block;

            if (isset($block['text'])) {
                $output .= $block['text'];

                continue;
            }

            if (! isset($block['toolUse'])) {
                continue;
            }

            if ($structured && ($block['toolUse']['name'] ?? '') === 'output_structured_data') {
                $structuredOutput = json_encode($block['toolUse']['input'] ?? []);

                continue;
            }

            $toolCalls[] = new ToolCall(
                $block['toolUse']['toolUseId'] ?? '',
                $block['toolUse']['name'] ?? '',
                $block['toolUse']['input'] ?? [],
                $block['toolUse']['toolUseId'] ?? null,
            );
        }

        $finishReason = $this->extractConverseFinishReason($result);

        if (empty($toolCalls) && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $structuredOutput ?? $output,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            providerContentBlocks: $providerContentBlocks,
        );
    }

    protected function extractConverseFinishReason(array $data): FinishReason
    {
        return match ($data['stopReason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }
}
