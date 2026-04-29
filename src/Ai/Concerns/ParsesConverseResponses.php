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

trait ParsesConverseResponses
{
    protected function parseConverseResponse(
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
        return $this->processConverseResponse(
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

    protected function processConverseResponse(
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
        $content = $data['output']['message']['content'] ?? [];

        $text = $this->extractConverseText($content);
        $toolCalls = $this->extractConverseToolCalls($content);
        $usage = $this->extractConverseUsage($data);
        $finishReason = $this->extractConverseFinishReason($data);
        $meta = new Meta($provider->name(), $model);

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

            return $this->continueConverseWithToolResults(
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
            $structuredData = $this->extractConverseStructuredOutput($content);

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
     * Continue the conversation with tool results via the Converse API.
     */
    protected function continueConverseWithToolResults(
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
        $assistantContent = $previousData['output']['message']['content'] ?? [];

        $requestBody['messages'][] = [
            'role' => 'assistant',
            'content' => $this->ensureConverseToolInputIsObject($assistantContent),
        ];

        $toolResultContent = [];

        foreach ($toolResults as $result) {
            $toolResultContent[] = [
                'toolResult' => [
                    'toolUseId' => $result->id,
                    'content' => [
                        ['text' => $this->serializeToolResultOutput($result->result)],
                    ],
                ],
            ];
        }

        $requestBody['messages'][] = [
            'role' => 'user',
            'content' => $toolResultContent,
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->post($this->converseUrl($model), $requestBody),
        );

        return $this->processConverseResponse(
            $response->json(),
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
     * Extract text from Converse API content blocks.
     */
    protected function extractConverseText(array $content): string
    {
        $texts = [];

        foreach ($content as $block) {
            if (isset($block['text'])) {
                $texts[] = $block['text'];
            }
        }

        return implode('', $texts);
    }

    /**
     * Extract tool calls from Converse API content blocks.
     *
     * @return array<ToolCall>
     */
    protected function extractConverseToolCalls(array $content): array
    {
        $toolCalls = [];

        foreach ($content as $block) {
            if (isset($block['toolUse'])) {
                $toolUse = $block['toolUse'];

                $toolCalls[] = new ToolCall(
                    $toolUse['toolUseId'] ?? '',
                    $toolUse['name'] ?? '',
                    $toolUse['input'] ?? [],
                    $toolUse['toolUseId'] ?? null,
                );
            }
        }

        return $toolCalls;
    }

    protected function extractConverseUsage(array $data): Usage
    {
        $usage = $data['usage'] ?? [];

        return new Usage(
            $usage['inputTokens'] ?? 0,
            $usage['outputTokens'] ?? 0,
            $usage['cacheWriteInputTokens'] ?? 0,
            $usage['cacheReadInputTokens'] ?? 0,
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

    /**
     * Extract structured output from the synthetic tool call in Converse format.
     */
    protected function extractConverseStructuredOutput(array $content): array
    {
        foreach ($content as $block) {
            if (isset($block['toolUse']) && ($block['toolUse']['name'] ?? '') === 'output_structured_data') {
                return $block['toolUse']['input'] ?? [];
            }
        }

        return [];
    }

    /**
     * Ensure toolUse content blocks have input cast to object for JSON serialization.
     */
    protected function ensureConverseToolInputIsObject(array $content): array
    {
        return array_map(function (array $block) {
            if (isset($block['toolUse'])) {
                $block['toolUse']['input'] = (object) ($block['toolUse']['input'] ?? []);
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
