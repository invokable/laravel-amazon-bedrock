<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\EmbeddingsResponse;

trait GeneratesEmbeddings
{
    /**
     * Generate embedding vectors for the given inputs.
     *
     * Cohere Embed models accept a batch of texts in a single request,
     * while Amazon Titan Embeddings V2 accepts a single text per request.
     *
     * @param  string[]  $inputs
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        ?int $timeout = null,
    ): EmbeddingsResponse {
        if ($this->isCohereEmbedModel($model)) {
            return $this->generateCohereEmbeddings($provider, $model, $inputs, $dimensions, $timeout);
        }

        return $this->generateTitanEmbeddings($provider, $model, $inputs, $dimensions, $timeout);
    }

    /**
     * Generate embeddings using Amazon Titan Embeddings V2 (one request per input).
     *
     * @param  string[]  $inputs
     */
    protected function generateTitanEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        ?int $timeout = null,
    ): EmbeddingsResponse {
        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn () => $this->client($provider, $model, $timeout)
                    ->post($this->invokeUrl($model), [
                        'inputText' => $input,
                        'dimensions' => $dimensions,
                        'normalize' => true,
                    ]),
            )->json();

            $embeddings[] = $response['embedding'] ?? [];
            $totalTokens += $response['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            $totalTokens,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings using Cohere Embed models (batch request).
     *
     * Cohere Embed v3 and v4 accept all texts in a single request via the "texts" field.
     * Response format varies: v3 returns {"embeddings": {"float": [...]}},
     * while v4 may return {"embeddings": [[...]]} when no embedding_types specified.
     *
     * @param  string[]  $inputs
     */
    protected function generateCohereEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        ?int $timeout = null,
    ): EmbeddingsResponse {
        $body = [
            'texts' => array_values($inputs),
            'input_type' => 'search_document',
            'embedding_types' => ['float'],
        ];

        if ($this->isCohereEmbedV4($model)) {
            $body['output_dimension'] = $dimensions;
        }

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->post($this->invokeUrl($model), $body),
        )->json();

        $embeddings = $this->parseCohereEmbeddings($response);

        return new EmbeddingsResponse(
            $embeddings,
            0,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Parse embeddings from a Cohere Embed response.
     *
     * Cohere returns embeddings in {"embeddings": {"float": [[...], [...]]}} format
     * when embedding_types is specified.
     *
     * @return list<list<float>>
     */
    protected function parseCohereEmbeddings(array $response): array
    {
        $embeddings = $response['embeddings'] ?? [];

        if (isset($embeddings['float'])) {
            return $embeddings['float'];
        }

        return $embeddings;
    }

    /**
     * Determine if the model is a Cohere Embed model.
     */
    protected function isCohereEmbedModel(string $model): bool
    {
        return str_starts_with($model, 'cohere.embed');
    }

    /**
     * Determine if the model is Cohere Embed v4.
     */
    protected function isCohereEmbedV4(string $model): bool
    {
        return str_contains($model, 'embed-v4');
    }
}
