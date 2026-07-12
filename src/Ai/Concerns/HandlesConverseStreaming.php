<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

trait HandlesConverseStreaming
{
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

    protected function generateEventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    /**
     * Process a single Converse streaming step.
     *
     * @return Generator<int, StreamEvent, mixed, StepResponse>
     */
    protected function processConverseStreamStep(
        string $invocationId,
        Provider|TextProvider $provider,
        string $model,
        $stream,
        bool $structured,
    ): Generator {
        $messageId = $this->generateEventId();
        $timestamp = time();
        $totalUsage = new Usage;

        yield (new StreamStart(
            $this->generateEventId(),
            $provider->name(),
            $model,
            $timestamp,
        ))->withInvocationId($invocationId);

        $assistantText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $structuredOutput = null;
        $currentBlockIndex = null;
        $currentBlockType = '';
        $responseContent = [];
        $stopReason = 'stop';

        foreach ($this->decodeConverseEventStream($stream) as [$eventType, $eventData]) {
            if ($eventType === 'contentBlockStart') {
                $start = $eventData['start'] ?? [];
                $blockIndex = $eventData['contentBlockIndex'] ?? count($responseContent);
                $currentBlockIndex = $blockIndex;

                if (isset($start['toolUse'])) {
                    $currentBlockType = 'tool_use';

                    $pendingToolCalls[$blockIndex] = [
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
                    $responseContent[$blockIndex] = ['text' => ''];

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                }

                continue;
            }

            if ($eventType === 'contentBlockDelta') {
                $delta = $eventData['delta'] ?? [];
                $blockIndex = $eventData['contentBlockIndex'] ?? $currentBlockIndex;

                if (isset($delta['text'])) {
                    $assistantText .= $delta['text'];
                    $responseContent[$blockIndex]['text'] = ($responseContent[$blockIndex]['text'] ?? '').$delta['text'];

                    yield (new TextDelta(
                        $this->generateEventId(),
                        $messageId,
                        $delta['text'],
                        time(),
                    ))->withInvocationId($invocationId);

                    continue;
                }

                if (isset($delta['toolUse']['input']) && isset($pendingToolCalls[$blockIndex])) {
                    $pendingToolCalls[$blockIndex]['arguments'] .= $delta['toolUse']['input'];
                }

                continue;
            }

            if ($eventType === 'contentBlockStop') {
                $blockIndex = $eventData['contentBlockIndex'] ?? $currentBlockIndex;

                if ($currentBlockType === 'text') {
                    yield (new TextEnd(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
                } elseif ($currentBlockType === 'tool_use' && isset($pendingToolCalls[$blockIndex])) {
                    $call = $pendingToolCalls[$blockIndex];
                    $arguments = json_decode($call['arguments'] ?: '{}', true) ?? [];

                    if ($structured && ($call['name'] ?? '') === 'output_structured_data') {
                        $structuredOutput = json_encode($arguments);
                    } else {
                        $toolCall = new ToolCall($call['id'], $call['name'], $arguments, $call['id']);
                        $toolCalls[] = $toolCall;

                        $responseContent[$blockIndex]['toolUse']['input'] = $arguments;

                        yield (new ToolCallEvent(
                            $this->generateEventId(),
                            $toolCall,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }

                $currentBlockType = '';

                continue;
            }

            if ($eventType === 'messageStop') {
                $stopReason = $eventData['stopReason'] ?? 'stop';

                continue;
            }

            if ($eventType === 'metadata') {
                $usage = $eventData['usage'] ?? [];
                $totalUsage = new Usage(
                    promptTokens: $usage['inputTokens'] ?? 0,
                    completionTokens: $usage['outputTokens'] ?? 0,
                    cacheWriteInputTokens: $usage['cacheWriteInputTokens'] ?? 0,
                    cacheReadInputTokens: $usage['cacheReadInputTokens'] ?? 0,
                );
            }
        }

        $finishReason = match ($stopReason) {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            default => FinishReason::Unknown,
        };

        if (empty($toolCalls) && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $structuredOutput ?? $assistantText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $totalUsage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            providerContentBlocks: array_values($responseContent),
        );
    }
}
