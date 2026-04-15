<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Generator;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

class BedrockGateway implements AudioGateway, EmbeddingGateway, ImageGateway, RerankingGateway, TextGateway, TranscriptionGateway
{
    use Concerns\BuildsConverseRequests;
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesBedrockClient;
    use Concerns\DetectsModelApi;
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\GeneratesTranscriptions;
    use Concerns\HandlesConverseStreaming;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesConverseResponses;
    use Concerns\ParsesTextResponses;
    use Concerns\Reranks;
    use HandlesFailoverErrors;
    use InvokesTools;

    public function __construct()
    {
        $this->initializeToolCallbacks();
    }

    /**
     * Bedrock may return 529 (Anthropic overloaded) in addition to 503.
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return [503, 529];
    }

    public function generateText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): TextResponse {
        if ($this->useConverseApi($model)) {
            return $this->generateConverseText($provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);
        }

        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->post($this->invokeUrl($model), $body),
        );

        return $this->parseTextResponse($response->json(), $provider, $model, filled($schema), $tools, $schema, $options, $body, $timeout);
    }

    public function streamText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages = [],
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
        ?int $timeout = null,
    ): Generator {
        if ($this->useConverseApi($model)) {
            yield from $this->streamConverseText($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options, $timeout);

            return;
        }

        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->withOptions(['stream' => true])
                ->post($this->streamUrl($model), $body),
        );

        yield from $this->processTextStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $options,
            $response->getBody(),
            $body,
            0,
            null,
            $timeout,
        );
    }

    /**
     * Generate text using the Converse API for non-Anthropic models.
     */
    protected function generateConverseText(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): TextResponse {
        $body = $this->buildConverseRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->post($this->converseUrl($model), $body),
        );

        return $this->parseConverseResponse($response->json(), $provider, $model, filled($schema), $tools, $schema, $options, $body, $timeout);
    }

    /**
     * Stream text using the Converse API for non-Anthropic models.
     */
    protected function streamConverseText(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
    ): Generator {
        $body = $this->buildConverseRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->withOptions(['stream' => true])
                ->post($this->converseStreamUrl($model), $body),
        );

        yield from $this->processConverseStream(
            $invocationId,
            $provider,
            $model,
            $tools,
            $options,
            $response->getBody(),
            $body,
            0,
            null,
            $timeout,
        );
    }
}
