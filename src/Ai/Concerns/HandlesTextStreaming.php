<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Laravel\Ai\Streaming\Events\ToolResult as ToolResultEvent;

trait HandlesTextStreaming
{
    /**
     * Process a Bedrock streaming response (AWS EventStream format).
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?TextGenerationOptions $options,
        $streamBody,
        array $requestBody = [],
        int $depth = 0,
        ?int $maxSteps = null,
        ?int $timeout = null,
    ): Generator {
        $maxSteps ??= $options?->maxSteps;

        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;

        $currentBlockType = '';
        $currentBlockIndex = -1;
        $currentBlockText = '';
        $currentToolIndex = -1;
        $pendingToolCalls = [];
        $responseContent = [];

        $inputTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
        $usage = null;
        $stopReason = '';

        foreach ($this->decodeEventStream($streamBody) as $event) {
            $type = $event['type'] ?? '';

            if ($type === 'message_start' && ! $streamStartEmitted) {
                $streamStartEmitted = true;

                $messageStartUsage = $event['message']['usage'] ?? [];
                $inputTokens = $messageStartUsage['input_tokens'] ?? 0;
                $cacheCreationTokens = $messageStartUsage['cache_creation_input_tokens'] ?? 0;
                $cacheReadTokens = $messageStartUsage['cache_read_input_tokens'] ?? 0;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $event['message']['model'] ?? $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'content_block_start') {
                $blockType = $event['content_block']['type'] ?? '';
                $currentBlockType = $blockType;
                $currentBlockIndex = $event['index'] ?? count($responseContent);

                if ($blockType === 'text') {
                    $currentBlockText = '';

                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($blockType === 'tool_use') {
                    $currentToolIndex++;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $event['content_block']['id'] ?? '',
                        'name' => $event['content_block']['name'] ?? '',
                        'arguments' => '',
                    ];
                }

                if (isset($event['content_block'])) {
                    $responseContent[$event['index'] ?? count($responseContent)] = $event['content_block'];
                }

                continue;
            }

            if ($type === 'content_block_delta') {
                $deltaType = $event['delta']['type'] ?? '';

                if ($deltaType === 'text_delta') {
                    $textDelta = (string) ($event['delta']['text'] ?? '');

                    if ($textDelta !== '') {
                        if (! $textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        $currentBlockText .= $textDelta;

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                } elseif ($deltaType === 'input_json_delta' && $currentBlockType === 'tool_use') {
                    $partial = $event['delta']['partial_json'] ?? '';

                    if ($currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop') {
                if ($currentBlockType === 'text' && $textStartEmitted) {
                    if (isset($responseContent[$currentBlockIndex])) {
                        $responseContent[$currentBlockIndex]['text'] = $currentBlockText;
                    }

                    $textStartEmitted = false;

                    yield (new TextEnd(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                } elseif ($currentBlockType === 'tool_use' && $currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                    $call = $pendingToolCalls[$currentToolIndex];
                    $parsedArguments = json_decode($call['arguments'] ?: '{}', true) ?? [];

                    $index = $event['index'] ?? $currentToolIndex;

                    if (isset($responseContent[$index])) {
                        $responseContent[$index]['input'] = $parsedArguments;
                    }

                    $pendingToolCalls[$currentToolIndex]['parsed_arguments'] = $parsedArguments;

                    yield (new ToolCallEvent(
                        $this->generateEventId(),
                        new ToolCall(
                            $call['id'],
                            $call['name'],
                            $parsedArguments,
                            $call['id'],
                        ),
                        time(),
                    ))->withInvocationId($invocationId);
                }

                $currentBlockType = '';

                continue;
            }

            if ($type === 'message_delta') {
                $stopReason = $event['delta']['stop_reason'] ?? '';
                $deltaUsage = $event['usage'] ?? [];

                $usage = new Usage(
                    $inputTokens,
                    $deltaUsage['output_tokens'] ?? 0,
                    $cacheCreationTokens,
                    $cacheReadTokens,
                );
            }
        }

        $realToolCalls = array_filter(
            $pendingToolCalls,
            fn (array $tc) => ($tc['name'] ?? '') !== 'output_structured_data',
        );

        if (filled($realToolCalls) && $stopReason === 'tool_use') {
            yield from $this->handleStreamingToolCalls(
                $invocationId,
                $provider,
                $model,
                $tools,
                $options,
                $realToolCalls,
                $responseContent,
                $requestBody,
                $depth,
                $maxSteps,
                $timeout,
            );

            return;
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            'stop',
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
    }

    /**
     * Handle tool calls detected during streaming.
     */
    protected function handleStreamingToolCalls(
        string $invocationId,
        Provider $provider,
        string $model,
        array $tools,
        ?TextGenerationOptions $options,
        array $pendingToolCalls,
        array $responseContent,
        array $requestBody,
        int $depth,
        ?int $maxSteps,
        ?int $timeout = null,
    ): Generator {
        $mappedToolCalls = $this->mapStreamToolCalls($pendingToolCalls);

        $toolResults = [];

        foreach ($mappedToolCalls as $toolCall) {
            $tool = $this->findTool($toolCall->name, $tools);

            if ($tool === null) {
                continue;
            }

            $result = $this->executeTool($tool, $toolCall->arguments);

            $toolResult = new ToolResult(
                $toolCall->id,
                $toolCall->name,
                $toolCall->arguments,
                $result,
                $toolCall->resultId,
            );

            $toolResults[] = $toolResult;

            yield (new ToolResultEvent(
                $this->generateEventId(),
                $toolResult,
                true,
                null,
                time(),
            ))->withInvocationId($invocationId);
        }

        if ($depth + 1 >= ($maxSteps ?? round(count($tools) * 1.5))) {
            yield (new StreamEnd(
                $this->generateEventId(),
                'stop',
                new Usage(0, 0),
                time(),
            ))->withInvocationId($invocationId);

            return;
        }

        $requestBody['messages'][] = [
            'role' => 'assistant',
            'content' => $this->ensureToolInputIsObject(array_values($responseContent)),
        ];

        $requestBody['messages'][] = [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $result) => [
                'type' => 'tool_result',
                'tool_use_id' => $result->id,
                'content' => $this->serializeToolResultOutput($result->result),
            ], $toolResults),
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->withOptions(['stream' => true])
                ->post($this->streamUrl($model), $requestBody),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $options,
            $response->getBody(),
            $requestBody,
            $depth + 1,
            $maxSteps,
            $timeout,
        );
    }

    /**
     * Map raw streaming tool call data to ToolCall DTOs.
     *
     * @return array<ToolCall>
     */
    protected function mapStreamToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $tc) => new ToolCall(
            $tc['id'] ?? '',
            $tc['name'] ?? '',
            $tc['parsed_arguments'] ?? json_decode($tc['arguments'] ?? '{}', true) ?? [],
            $tc['id'] ?? null,
        ), array_values($toolCalls));
    }

    /**
     * Decode AWS EventStream binary format into Anthropic JSON events.
     */
    protected function decodeEventStream($streamBody): Generator
    {
        $events = new NonSeekableStreamDecodingEventStreamIterator($streamBody);

        foreach ($events as $event) {
            $payload = json_decode(data_get($event, 'payload')->getContents(), true);

            yield json_decode(base64_decode(data_get($payload, 'bytes')), true);
        }
    }

    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }
}
