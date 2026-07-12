<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Generator;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Throwable;

class BedrockGateway implements AudioGateway, EmbeddingGateway, ImageGateway, RerankingGateway, StepTextGateway
{
    use Concerns\BuildsConverseRequests;
    use Concerns\CreatesBedrockClient;
    use Concerns\GeneratesAudio;
    use Concerns\GeneratesEmbeddings;
    use Concerns\GeneratesImages;
    use Concerns\HandlesConverseStreaming;
    use Concerns\MapsConverseAttachments;
    use Concerns\ParsesConverseResponses;
    use Concerns\Reranks;
    use DecodesStructuredOutput;
    use HandlesFailoverErrors;

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $client = $this->client($provider, $model, $timeout);

        $body = $this->buildConverseRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->post($this->converseUrl($model), $body),
            );

            $result = $response->json();
        } catch (Throwable $e) {
            throw $this->mapBedrockException($e, $provider->name(), $model);
        }

        return $this->parseConverseTextStep($result, $provider, $model, filled($schema));
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $client = $this->client($provider, $model, $timeout);

        $body = $this->buildConverseRequestBody($provider, $model, $instructions, $messages, $tools, $schema, $options);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $client->withOptions(['stream' => true])
                    ->post($this->converseStreamUrl($model), $body),
            );
        } catch (Throwable $e) {
            throw $this->mapBedrockException($e, $provider->name(), $model);
        }

        return yield from $this->processConverseStreamStep(
            $invocationId,
            $provider,
            $model,
            $response->getBody(),
            filled($schema),
        );
    }

    /**
     * Map Bedrock exceptions to AI SDK exceptions.
     */
    protected function mapBedrockException(Throwable $e, string $provider, string $model): Throwable
    {
        // Exception mapping logic if needed
        return $e;
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
}
