<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

trait ParsesTextResponses
{
    protected function parseTextResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured = false,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        array $requestBody = [],
        ?int $timeout = null,
    ): TextResponse {
        return $this->processResponse(
            $data,
            $provider,
            $model,
            $structured,
            $tools,
            $schema,
            new Collection,
            new Collection,
            $requestBody,
            maxSteps: $options?->maxSteps,
            timeout: $timeout,
        );
    }

    protected function processResponse(
        array $data,
        Provider $provider,
        string $model,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $requestBody,
        int $depth = 0,
        ?int $maxSteps = null,
        ?int $timeout = null,
    ): TextResponse {
        $content = $data['content'] ?? [];

        $text = $this->extractText($content);
        $toolCalls = $this->extractToolCalls($content);
        $usage = $this->extractUsage($data);
        $finishReason = $this->extractFinishReason($data);
        $meta = new Meta($provider->name(), $data['model'] ?? $model);

        $realToolCalls = array_filter($toolCalls, fn (ToolCall $tc) => $tc->name !== 'output_structured_data');
        $hasStructuredToolCall = count($realToolCalls) < count($toolCalls);
        $toolResults = [];

        $shouldContinue = $finishReason === FinishReason::ToolCalls
            && filled($realToolCalls)
            && $depth + 1 < ($maxSteps ?? round(count($tools) * 1.5));

        if ($shouldContinue) {
            $toolResults = $this->executeToolCalls($realToolCalls, $tools);
        }

        $steps->push(new Step($text, $toolCalls, $toolResults, $finishReason, $usage, $meta));

        $messages->push(new AssistantMessage($text, collect($toolCalls)));

        if ($shouldContinue) {
            $messages->push(new ToolResultMessage(collect($toolResults)));

            return $this->continueWithToolResults(
                $data,
                $provider,
                $model,
                $structured,
                $tools,
                $schema,
                $steps,
                $messages,
                $requestBody,
                $toolResults,
                $depth + 1,
                $maxSteps,
                $timeout,
            );
        }

        if ($structured || $hasStructuredToolCall) {
            $structuredData = $this->extractStructuredOutput($content);

            if (empty($structuredData) && filled($text)) {
                $structuredData = json_decode($text, true) ?? [];
            }

            return (new StructuredTextResponse(
                $structuredData,
                json_encode($structuredData) ?: '',
                $this->combineUsage($steps),
                $meta,
            ))->withToolCallsAndResults(
                toolCalls: $steps->flatMap(fn (Step $s) => $s->toolCalls),
                toolResults: $steps->flatMap(fn (Step $s) => $s->toolResults),
            )->withSteps($steps);
        }

        return (new TextResponse(
            $text,
            $this->combineUsage($steps),
            $meta,
        ))->withMessages($messages)->withSteps($steps);
    }

    /**
     * Execute tool calls and return tool results.
     *
     * @param  array<ToolCall>  $toolCalls
     * @return array<ToolResult>
     */
    protected function executeToolCalls(array $toolCalls, array $tools): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $results[] = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );
        }

        return $results;
    }

    /**
     * Continue the conversation with tool results by making a follow-up request.
     */
    protected function continueWithToolResults(
        array $previousData,
        Provider $provider,
        string $model,
        bool $structured,
        array $tools,
        ?array $schema,
        Collection $steps,
        Collection $messages,
        array $requestBody,
        array $toolResults,
        int $depth,
        ?int $maxSteps,
        ?int $timeout = null,
    ): TextResponse {
        $requestBody['messages'][] = [
            'role' => 'assistant',
            'content' => $this->ensureToolInputIsObject($previousData['content'] ?? []),
        ];

        $toolResultContent = [];

        foreach ($toolResults as $result) {
            $toolResultContent[] = [
                'type' => 'tool_result',
                'tool_use_id' => $result->id,
                'content' => $this->serializeToolResultOutput($result->result),
            ];
        }

        $requestBody['messages'][] = [
            'role' => 'user',
            'content' => $toolResultContent,
        ];

        $response = $this->client($provider, $model, $timeout)
            ->post($this->invokeUrl($model), $requestBody);

        $data = $response->json();

        return $this->processResponse(
            $data,
            $provider,
            $model,
            $structured,
            $tools,
            $schema,
            $steps,
            $messages,
            $requestBody,
            $depth,
            $maxSteps,
            $timeout,
        );
    }

    /**
     * Extract tool calls from Anthropic content blocks.
     *
     * @return array<ToolCall>
     */
    protected function extractToolCalls(array $content): array
    {
        $toolUseBlocks = array_filter($content, fn (array $block) => ($block['type'] ?? '') === 'tool_use');

        return array_values(array_map(fn (array $block) => new ToolCall(
            $block['id'] ?? '',
            $block['name'] ?? '',
            $block['input'] ?? [],
            $block['id'] ?? null,
        ), $toolUseBlocks));
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

    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stop_reason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Extract structured output from the synthetic tool call.
     */
    protected function extractStructuredOutput(array $content): array
    {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === 'output_structured_data') {
                return $block['input'] ?? [];
            }
        }

        return [];
    }

    /**
     * Ensure tool_use content blocks have their input cast to object for JSON serialization.
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block) {
            if (($block['type'] ?? '') === 'tool_use') {
                $block['input'] = (object) ($block['input'] ?? []);
            }

            return $block;
        }, $content);
    }

    /**
     * Combine usage across all steps.
     */
    protected function combineUsage(Collection $steps): Usage
    {
        return $steps->reduce(
            fn (Usage $carry, Step $step) => $carry->add($step->usage),
            new Usage(0, 0)
        );
    }
}
