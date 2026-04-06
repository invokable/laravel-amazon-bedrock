<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Closure;
use Exception;
use Generator;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Revolution\Amazon\Bedrock\Text\PendingRequest;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;

/**
 * Laravel AI SDK Integration.
 */
class BedrockGateway implements TextGateway
{
    protected ?Closure $invokingToolCallback = null;

    protected ?Closure $toolInvokedCallback = null;

    /**
     * Generate text representing the next message in a conversation.
     *
     * @param  array<string, Type>|null  $schema
     *
     * @throws Exception
     */
    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
    {
        $request = $this->buildRequest($provider, $model, $timeout);

        if ($instructions) {
            $request->withSystemPrompt($instructions);
        }

        if ($messages) {
            $request->withMessages($this->toBedrockMessages($messages));
        }

        if ($options?->maxTokens) {
            $request->withMaxTokens($options->maxTokens);
        }

        if ($options?->temperature !== null) {
            $request->usingTemperature($options->temperature);
        }

        $response = $request->asText();

        return new TextResponse(
            text: $response->text,
            usage: new Usage(
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
                cacheWriteInputTokens: $response->usage->cacheWriteInputTokens,
                cacheReadInputTokens: $response->usage->cacheReadInputTokens,
            ),
            meta: new Meta(
                provider: $provider->name(),
                model: $model,
            ),
        );
    }

    /**
     * Stream text representing the next message in a conversation.
     *
     * @param  array<string, Type>|null  $schema
     */
    public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): Generator
    {
        $request = $this->buildRequest($provider, $model, $timeout);

        if ($instructions) {
            $request->withSystemPrompt($instructions);
        }

        if ($options?->maxTokens) {
            $request->withMaxTokens($options->maxTokens);
        }

        if ($options?->temperature !== null) {
            $request->usingTemperature($options->temperature);
        }

        $events = $request
            ->withMessages($this->toBedrockMessages($messages))
            ->asStream();

        foreach ($events as $event) {
            if (! is_null($mapped = $this->toLaravelStreamEvent(
                $invocationId, $event, $provider->name(), $model,
            ))) {
                yield $mapped;
            }
        }
    }

    protected function buildRequest(TextProvider $provider, string $model, ?int $timeout): PendingRequest
    {
        $config = $provider->config;

        $request = new PendingRequest;

        $request->using('', $model);

        if (! empty($config['region'])) {
            $request->withRegion($config['region']);
        }

        if (! empty($config['key'])) {
            $request->withApiKey($config['key']);
        }

        if (! empty($timeout)) {
            $request->withTimeout($timeout);
        } elseif (! empty($config['timeout'])) {
            $request->withTimeout((int) $config['timeout']);
        }

        if (! empty($config['max_tokens'])) {
            $request->withMaxTokens((int) $config['max_tokens']);
        }

        return $request;
    }

    protected function toBedrockMessages(array $messages): array
    {
        return collect($messages)
            ->map(function ($message) {
                $message = Message::tryFrom($message);

                if ($message->role === MessageRole::User) {
                    return UserMessage::make($message->content)->toArray();
                }

                if ($message->role === MessageRole::Assistant) {
                    return AssistantMessage::make($message->content)->toArray();
                }
            })->filter()->values()->toArray();
    }

    /**
     * @param  array{type: string}  $event
     */
    protected function toLaravelStreamEvent($invocationId, array $event, $provider, $model): ?StreamEvent
    {
        return tap(match (data_get($event, 'type')) {
            'content_block_delta' => new TextDelta(
                id: Str::uuid()->toString(),
                messageId: Str::ulid()->toString(),
                delta: data_get($event, 'delta.text'),
                timestamp: now()->timestamp,
            ),
            'content_block_start' => new TextStart(
                id: Str::uuid()->toString(),
                messageId: Str::ulid()->toString(),
                timestamp: now()->timestamp,
            ),
            'content_block_stop' => new TextEnd(
                id: Str::uuid()->toString(),
                messageId: Str::ulid()->toString(),
                timestamp: now()->timestamp,
            ),
            'message_start' => new StreamStart(
                id: Str::uuid()->toString(),
                provider: $provider,
                model: $model,
                timestamp: now()->timestamp,
            ),
            default => null,
        }, function ($event) use ($invocationId) {
            $event?->withInvocationId($invocationId);
        });
    }

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): self
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }
}
