<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\RerankingResponse;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

function fakeRerankResponse(array $results = []): array
{
    return [
        'results' => $results ?: [
            [
                'index' => 1,
                'relevanceScore' => 0.95,
                'document' => [
                    'type' => 'TEXT',
                    'textDocument' => ['text' => 'Second document'],
                ],
            ],
            [
                'index' => 0,
                'relevanceScore' => 0.72,
                'document' => [
                    'type' => 'TEXT',
                    'textDocument' => ['text' => 'First document'],
                ],
            ],
        ],
    ];
}

describe('BedrockGateway rerank', function () {
    test('returns a RerankingResponse instance', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['First document', 'Second document'],
            query: 'What is relevant?',
        );

        expect($response)->toBeInstanceOf(RerankingResponse::class);
    });

    test('returns ranked documents with correct indices and scores', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['First document', 'Second document'],
            query: 'What is relevant?',
        );

        expect($response->results)->toHaveCount(2);
        expect($response->results[0])->toBeInstanceOf(RankedDocument::class);
        expect($response->results[0]->index)->toBe(1);
        expect($response->results[0]->score)->toBe(0.95);
        expect($response->results[0]->document)->toBe('Second document');
        expect($response->results[1]->index)->toBe(0);
        expect($response->results[1]->score)->toBe(0.72);
        expect($response->results[1]->document)->toBe('First document');
    });

    test('first() returns the top-ranked document', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['First document', 'Second document'],
            query: 'What is relevant?',
        );

        expect($response->first()->index)->toBe(1);
        expect($response->first()->score)->toBe(0.95);
    });

    test('documents() returns reranked document text', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['First document', 'Second document'],
            query: 'What is relevant?',
        );

        $docs = $response->documents();
        expect($docs)->toHaveCount(2);
        expect($docs[0])->toBe('Second document');
        expect($docs[1])->toBe('First document');
    });

    test('sends request to bedrock-agent-runtime endpoint', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.90, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc A']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Doc A'],
            query: 'query',
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-agent-runtime.us-east-1.amazonaws.com/rerank'
            );
        });
    });

    test('sends correct request body structure', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Document one', 'Document two'],
            query: 'Search query',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Check queries
            expect($body['queries'])->toHaveCount(1);
            expect($body['queries'][0]['type'])->toBe('TEXT');
            expect($body['queries'][0]['textQuery']['text'])->toBe('Search query');

            // Check sources
            expect($body['sources'])->toHaveCount(2);
            expect($body['sources'][0]['type'])->toBe('INLINE');
            expect($body['sources'][0]['inlineDocumentSource']['type'])->toBe('TEXT');
            expect($body['sources'][0]['inlineDocumentSource']['textDocument']['text'])->toBe('Document one');
            expect($body['sources'][1]['inlineDocumentSource']['textDocument']['text'])->toBe('Document two');

            // Check reranking configuration
            expect($body['rerankingConfiguration']['type'])->toBe('BEDROCK');
            expect($body['rerankingConfiguration']['bedrockRerankingConfiguration']['modelConfiguration']['modelArn'])
                ->toBe('arn:aws:bedrock:us-east-1::foundation-model/cohere.rerank-v3-5:0');

            return true;
        });
    });

    test('includes numberOfResults when limit is specified', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.95, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc A']]],
                ['index' => 1, 'relevanceScore' => 0.80, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc B']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Doc A', 'Doc B', 'Doc C'],
            query: 'query',
            limit: 2,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['rerankingConfiguration']['bedrockRerankingConfiguration']['numberOfResults'] === 2;
        });
    });

    test('omits numberOfResults when limit is null', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.90, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc A']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Doc A'],
            query: 'query',
            limit: null,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('numberOfResults', $body['rerankingConfiguration']['bedrockRerankingConfiguration']);
        });
    });

    test('uses configured region in endpoint URL and model ARN', function () {
        Http::fake([
            'bedrock-agent-runtime.ap-northeast-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.90, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc A']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $gateway->rerank(
            provider: makeProvider(['region' => 'ap-northeast-1']),
            model: 'amazon.rerank-v1:0',
            documents: ['Doc A'],
            query: 'query',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $modelArn = $body['rerankingConfiguration']['bedrockRerankingConfiguration']['modelConfiguration']['modelArn'];

            return str_contains($request->url(), 'bedrock-agent-runtime.ap-northeast-1.amazonaws.com')
                && $modelArn === 'arn:aws:bedrock:ap-northeast-1::foundation-model/amazon.rerank-v1:0';
        });
    });

    test('meta contains provider name and model', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.90, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Doc A']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Doc A'],
            query: 'query',
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('cohere.rerank-v3-5:0');
    });

    test('handles single document', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.99, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'Only document']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Only document'],
            query: 'query',
        );

        expect($response)->toHaveCount(1);
        expect($response->first()->document)->toBe('Only document');
        expect($response->first()->score)->toBe(0.99);
    });

    test('handles empty results', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(['results' => []]),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['Doc A'],
            query: 'query',
        );

        expect($response)->toHaveCount(0);
        expect($response->first())->toBeNull();
    });

    test('works with amazon rerank model', function () {
        Http::fake([
            'bedrock-agent-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeRerankResponse([
                ['index' => 0, 'relevanceScore' => 0.88, 'document' => ['type' => 'TEXT', 'textDocument' => ['text' => 'A doc']]],
            ])),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->rerank(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'amazon.rerank-v1:0',
            documents: ['A doc'],
            query: 'query',
        );

        expect($response->first()->score)->toBe(0.88);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains(
                $body['rerankingConfiguration']['bedrockRerankingConfiguration']['modelConfiguration']['modelArn'],
                'amazon.rerank-v1:0'
            );
        });
    });
});

describe('BedrockProvider reranking defaults', function () {
    test('returns default reranking model', function () {
        $provider = makeProvider();
        expect($provider->defaultRerankingModel())->toBe('cohere.rerank-v3-5:0');
    });

    test('uses configured reranking model', function () {
        $provider = makeProvider([
            'models' => ['reranking' => ['default' => 'amazon.rerank-v1:0']],
        ]);
        expect($provider->defaultRerankingModel())->toBe('amazon.rerank-v1:0');
    });

    test('returns BedrockGateway as reranking gateway', function () {
        $provider = makeProvider();
        expect($provider->rerankingGateway())->toBeInstanceOf(BedrockGateway::class);
    });
});
