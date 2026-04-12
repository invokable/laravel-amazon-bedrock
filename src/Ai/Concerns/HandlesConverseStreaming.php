<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
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

trait HandlesConverseStreaming
{
    /**
     * Process a Converse API streaming response.
     */
    protected function processConverseStream(
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
        $currentBlockText = '';
        $currentToolIndex = -1;
        $pendingToolCalls = [];
        $responseContent = [];

        $usage = null;
        $stopReason = '';

        foreach ($this->decodeConverseEventStream($streamBody) as [$eventType, $eventData]) {
            if ($eventType === 'messageStart' && ! $streamStartEmitted) {
                $streamStartEmitted = true;

                yield (new StreamStart(
                    $this->generateEventId(),
                    $provider->name(),
                    $model,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($eventType === 'contentBlockStart') {
                $start = $eventData['start'] ?? [];
                $blockIndex = $eventData['contentBlockIndex'] ?? count($responseContent);

                if (isset($start['toolUse'])) {
                    $currentBlockType = 'tool_use';
                    $currentToolIndex++;

                    $pendingToolCalls[$currentToolIndex] = [
                        'id' => $start['toolUse']['toolUseId'] ?? '',
                        'name' => $start['toolUse']['name'] ?? '',
                        'arguments' => '',
                    ];

                    $responseContent[$blockIndex] = [
                        'toolUse' => [
                            'toolUseId' => $start['toolUse']['toolUseId'] ?? '',
                            'name' => $start['toolUse']['name'] ?? '',
                            'input' => [],
                        ],
                    ];
                } else {
                    $currentBlockType = 'text';
                    $currentBlockText = '';

                    if (! $textStartEmitted) {
                        $textStartEmitted = true;

                        yield (new TextStart(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }

                    $responseContent[$blockIndex] = ['text' => ''];
                }

                continue;
            }

            if ($eventType === 'contentBlockDelta') {
                $delta = $eventData['delta'] ?? [];
                $blockIndex = $eventData['contentBlockIndex'] ?? 0;

                if (isset($delta['text'])) {
                    $textDelta = (string) $delta['text'];

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
                } elseif (isset($delta['toolUse'])) {
                    $partial = $delta['toolUse']['input'] ?? '';

                    if ($currentToolIndex >= 0 && isset($pendingToolCalls[$currentToolIndex])) {
                        $pendingToolCalls[$currentToolIndex]['arguments'] .= $partial;
                    }
                }

                continue;
            }

            if ($eventType === 'contentBlockStop') {
                $blockIndex = $eventData['contentBlockIndex'] ?? 0;

                if ($currentBlockType === 'text' && $textStartEmitted) {
                    if (isset($responseContent[$blockIndex])) {
                        $responseContent[$blockIndex]['text'] = $currentBlockText;
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

                    if (isset($responseContent[$blockIndex]['toolUse'])) {
                        $responseContent[$blockIndex]['toolUse']['input'] = $parsedArguments;
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

            if ($eventType === 'messageStop') {
                $stopReason = $eventData['stopReason'] ?? '';

                continue;
            }

            if ($eventType === 'metadata') {
                $usageData = $eventData['usage'] ?? [];

                $usage = new Usage(
                    $usageData['inputTokens'] ?? 0,
                    $usageData['outputTokens'] ?? 0,
                );
            }
        }

        $realToolCalls = array_filter(
            $pendingToolCalls,
            fn (array $tc) => ($tc['name'] ?? '') !== 'output_structured_data',
        );

        if (filled($realToolCalls) && $stopReason === 'tool_use') {
            yield from $this->handleConverseStreamingToolCalls(
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
     * Handle tool calls detected during Converse streaming.
     */
    protected function handleConverseStreamingToolCalls(
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
            'content' => $this->ensureConverseToolInputIsObject(array_values($responseContent)),
        ];

        $requestBody['messages'][] = [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $result) => [
                'toolResult' => [
                    'toolUseId' => $result->id,
                    'content' => [
                        ['text' => $this->serializeToolResultOutput($result->result)],
                    ],
                ],
            ], $toolResults),
        ];

        $response = $this->client($provider, $model, $timeout)
            ->withOptions(['stream' => true])
            ->post($this->converseStreamUrl($model), $requestBody);

        yield from $this->processConverseStream(
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
     * Decode Converse API EventStream into event type + data pairs.
     *
     * The Converse API uses the same AWS EventStream binary format, but events
     * are structured with `:event-type` headers and direct JSON payloads
     * (no base64 encoding like the InvokeModel stream).
     *
     * @return Generator<array{string, array}>
     */
    protected function decodeConverseEventStream($streamBody): Generator
    {
        $events = new NonSeekableStreamDecodingEventStreamIterator($streamBody);

        foreach ($events as $event) {
            $headers = $event['headers'] ?? [];
            $eventType = $headers[':event-type'] ?? '';

            $payload = $event['payload'] ?? null;

            if ($payload === null) {
                continue;
            }

            $data = json_decode($payload->getContents(), true);

            if (! is_array($data)) {
                continue;
            }

            // The event data is nested under the event type key
            $eventData = $data[$eventType] ?? $data;

            yield [$eventType, $eventData];
        }
    }
}
