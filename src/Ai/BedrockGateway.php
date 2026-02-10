<?php

namespace Revolution\Amazon\Bedrock\Ai;

use Closure;
use Generator;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;

/**
 * Laravel AI SDK Integration.
 */
class BedrockGateway implements Gateway
{
    protected $invokingToolCallback;

    protected $toolInvokedCallback;

    public function __construct(protected Dispatcher $events)
    {
        $this->invokingToolCallback = fn () => true;
        $this->toolInvokedCallback = fn () => true;
    }

    /**
     * Generate text representing the next message in a conversation.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
     */
    public function generateText(TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): TextResponse
    {
        $request = Bedrock::text();

        if ($model) {
            $request->using(provider: Bedrock::KEY, model: $model);
        }

        if ($instructions) {
            $request->withSystemPrompt($instructions);
        }

        if ($messages) {
            $request->withMessages($this->toBedrockMessages($messages));
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
     * Stream text representing the next message in a conversation.
     *
     * @param  array<string, \Illuminate\JsonSchema\Types\Type>|null  $schema
     */
    public function streamText(string $invocationId, TextProvider $provider, string $model, ?string $instructions, array $messages = [], array $tools = [], ?array $schema = null, ?TextGenerationOptions $options = null, ?int $timeout = null): Generator
    {
        // TODO: Implement streamText() method.
    }

    /**
     * Specify callbacks that should be invoked when tools are invoking / invoked.
     */
    public function onToolInvocation(Closure $invoking, Closure $invoked): Gateway
    {
        $this->invokingToolCallback = $invoking;
        $this->toolInvokedCallback = $invoked;

        return $this;
    }

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(AudioProvider $provider, string $model, string $text, string $voice, ?string $instructions = null): AudioResponse
    {
        // TODO: Implement generateAudio() method.
    }

    /**
     * Generate embedding vectors representing the given inputs.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions): EmbeddingsResponse
    {
        // TODO: Implement generateEmbeddings() method.
    }

    /**
     * Generate an image.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(ImageProvider $provider, string $model, string $prompt, array $attachments = [], ?string $size = null, ?string $quality = null, ?int $timeout = null): ImageResponse
    {
        // TODO: Implement generateImage() method.
    }

    /**
     * Generate text from the given audio.
     */
    public function generateTranscription(TranscriptionProvider $provider, string $model, TranscribableAudio $audio, ?string $language = null, bool $diarize = false): TranscriptionResponse
    {
        // TODO: Implement generateTranscription() method.
    }
}
