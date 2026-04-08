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
     * Amazon Titan Embeddings V2 accepts a single text per request,
     * so this loops over the inputs and aggregates the results.
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
        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            $response = $this->client($provider, $model, $timeout)
                ->post($this->invokeUrl($model), [
                    'inputText' => $input,
                    'dimensions' => $dimensions,
                    'normalize' => true,
                ])
                ->json();

            $embeddings[] = $response['embedding'] ?? [];
            $totalTokens += $response['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            $totalTokens,
            new Meta($provider->name(), $model),
        );
    }
}
