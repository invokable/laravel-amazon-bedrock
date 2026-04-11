<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Generator;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\InvokesTools;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\TextResponse;

class BedrockGateway implements EmbeddingGateway, ImageGateway, TextGateway
{
    use Concerns\BuildsTextRequests;
    use Concerns\CreatesBedrockClient;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\HandlesTextStreaming;
    use Concerns\MapsMessages;
    use Concerns\MapsTools;
    use Concerns\ParsesTextResponses;
    use InvokesTools;

    public function __construct()
    {
        $this->initializeToolCallbacks();
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
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->client($provider, $model, $timeout)
            ->post($this->invokeUrl($model), $body);

        return $this->parseTextResponse($response->json(), $provider, $model, $tools, $options, $body, $timeout);
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
        $body = $this->buildTextRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        $response = $this->client($provider, $model, $timeout)
            ->withOptions(['stream' => true])
            ->post($this->streamUrl($model), $body);

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
}
