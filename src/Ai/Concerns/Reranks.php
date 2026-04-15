<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;

trait Reranks
{
    /**
     * Rerank the given documents based on their relevance to the query.
     *
     * Uses the Bedrock Agent Runtime Rerank API endpoint.
     *
     * @see https://docs.aws.amazon.com/bedrock/latest/APIReference/API_agent-runtime_Rerank.html
     *
     * @param  array<int, string>  $documents
     */
    public function rerank(
        RerankingProvider $provider,
        string $model,
        array $documents,
        string $query,
        ?int $limit = null
    ): RerankingResponse {
        $region = $provider->additionalConfiguration()['region'] ?? 'us-east-1';

        $body = $this->buildRerankRequestBody($model, $documents, $query, $limit, $region);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->agentRuntimeClient($provider)
                ->post('rerank', $body),
        );

        return $this->parseRerankResponse($response->json(), $documents, $provider, $model);
    }

    /**
     * Build the request body for the Bedrock Rerank API.
     *
     * @param  array<int, string>  $documents
     */
    protected function buildRerankRequestBody(
        string $model,
        array $documents,
        string $query,
        ?int $limit,
        string $region,
    ): array {
        $sources = array_map(fn (string $document): array => [
            'type' => 'INLINE',
            'inlineDocumentSource' => [
                'type' => 'TEXT',
                'textDocument' => [
                    'text' => $document,
                ],
            ],
        ], $documents);

        $rerankingConfig = [
            'type' => 'BEDROCK',
            'bedrockRerankingConfiguration' => [
                'modelConfiguration' => [
                    'modelArn' => "arn:aws:bedrock:{$region}::foundation-model/{$model}",
                ],
            ],
        ];

        if ($limit !== null) {
            $rerankingConfig['bedrockRerankingConfiguration']['numberOfResults'] = $limit;
        }

        return [
            'queries' => [
                [
                    'type' => 'TEXT',
                    'textQuery' => [
                        'text' => $query,
                    ],
                ],
            ],
            'sources' => $sources,
            'rerankingConfiguration' => $rerankingConfig,
        ];
    }

    /**
     * Parse the Bedrock Rerank API response.
     *
     * @param  array<int, string>  $documents
     */
    protected function parseRerankResponse(
        array $data,
        array $documents,
        RerankingProvider $provider,
        string $model,
    ): RerankingResponse {
        $results = (new Collection($data['results'] ?? []))->map(
            fn (array $result): RankedDocument => new RankedDocument(
                index: $result['index'],
                document: $documents[$result['index']],
                score: (float) $result['relevanceScore'],
            )
        )->all();

        return new RerankingResponse(
            $results,
            new Meta($provider->name(), $model),
        );
    }
}
