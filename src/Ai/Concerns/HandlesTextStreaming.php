<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Aws\Api\Parser\NonSeekableStreamDecodingEventStreamIterator;
use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;

trait HandlesTextStreaming
{
    /**
     * Process a Bedrock streaming response (AWS EventStream format).
     */
    protected function processTextStream(
        string $invocationId,
        Provider $provider,
        string $model,
        $streamBody,
    ): Generator {
        $messageId = $this->generateEventId();
        $streamStartEmitted = false;
        $textStartEmitted = false;

        $inputTokens = 0;
        $cacheCreationTokens = 0;
        $cacheReadTokens = 0;
        $usage = null;

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

                if ($blockType === 'text' && ! $textStartEmitted) {
                    $textStartEmitted = true;

                    yield (new TextStart(
                        $this->generateEventId(),
                        $messageId,
                        time(),
                    ))->withInvocationId($invocationId);
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

                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $textDelta,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }

                continue;
            }

            if ($type === 'content_block_stop' && $textStartEmitted) {
                $textStartEmitted = false;

                yield (new TextEnd(
                    $this->generateEventId(),
                    $messageId,
                    time(),
                ))->withInvocationId($invocationId);

                continue;
            }

            if ($type === 'message_delta') {
                $deltaUsage = $event['usage'] ?? [];

                $usage = new Usage(
                    $inputTokens,
                    $deltaUsage['output_tokens'] ?? 0,
                    $cacheCreationTokens,
                    $cacheReadTokens,
                );
            }
        }

        yield (new StreamEnd(
            $this->generateEventId(),
            'stop',
            $usage ?? new Usage(0, 0),
            time(),
        ))->withInvocationId($invocationId);
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
