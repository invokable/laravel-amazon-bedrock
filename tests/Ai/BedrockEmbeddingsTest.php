<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

function fakeTitanEmbeddingResponse(array $embedding = [], int $tokenCount = 5): array
{
    return [
        'embedding' => $embedding ?: [0.1, 0.2, 0.3],
        'inputTextTokenCount' => $tokenCount,
    ];
}

describe('BedrockGateway generateEmbeddings', function () {
    test('returns an EmbeddingsResponse instance', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeTitanEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        expect($response)->toBeInstanceOf(EmbeddingsResponse::class);
    });

    test('returns embedding vectors for single input', function () {
        $vector = [0.1, 0.2, 0.3, 0.4, 0.5];

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeTitanEmbeddingResponse($vector, 3),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        expect($response->embeddings)->toHaveCount(1);
        expect($response->first())->toBe($vector);
    });

    test('loops over multiple inputs and returns all embeddings', function () {
        $vector1 = [0.1, 0.2, 0.3];
        $vector2 = [0.4, 0.5, 0.6];

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence()
                ->push(fakeTitanEmbeddingResponse($vector1, 3))
                ->push(fakeTitanEmbeddingResponse($vector2, 4)),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world', 'Foo bar'],
            dimensions: 1024,
        );

        expect($response->embeddings)->toHaveCount(2);
        expect($response->embeddings[0])->toBe($vector1);
        expect($response->embeddings[1])->toBe($vector2);
    });

    test('sums token counts across multiple inputs', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence()
                ->push(fakeTitanEmbeddingResponse(tokenCount: 5))
                ->push(fakeTitanEmbeddingResponse(tokenCount: 8)),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['First text', 'Second text'],
            dimensions: 1024,
        );

        expect($response->tokens)->toBe(13);
    });

    test('sends correct request body to Bedrock', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeTitanEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Test input text'],
            dimensions: 512,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['inputText'] === 'Test input text'
                && $body['dimensions'] === 512
                && $body['normalize'] === true;
        });
    });

    test('sends request to correct Bedrock URL', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeTitanEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-east-1.amazonaws.com/model/amazon.titan-embed-text-v2:0/invoke'
            );
        });
    });

    test('uses configured region in URL', function () {
        Http::fake([
            'bedrock-runtime.eu-west-1.amazonaws.com/*' => Http::response(fakeTitanEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(['region' => 'eu-west-1']),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.eu-west-1.amazonaws.com');
        });
    });

    test('meta contains provider name and model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeTitanEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello'],
            dimensions: 1024,
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('amazon.titan-embed-text-v2:0');
    });
});

function fakeCohereEmbeddingResponse(array $floatEmbeddings = [], string $responseType = 'embeddings_by_type'): array
{
    return [
        'id' => 'test-id-123',
        'embeddings' => [
            'float' => $floatEmbeddings ?: [[0.1, 0.2, 0.3], [0.4, 0.5, 0.6]],
        ],
        'texts' => ['Hello world', 'Foo bar'],
        'response_type' => $responseType,
    ];
}

describe('BedrockGateway generateEmbeddings (Cohere Embed v3)', function () {
    test('returns an EmbeddingsResponse for Cohere Embed model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello world', 'Foo bar'],
            dimensions: 1024,
        );

        expect($response)->toBeInstanceOf(EmbeddingsResponse::class);
    });

    test('returns all embedding vectors from batch response', function () {
        $vector1 = [0.1, 0.2, 0.3];
        $vector2 = [0.4, 0.5, 0.6];

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeCohereEmbeddingResponse([$vector1, $vector2]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello world', 'Foo bar'],
            dimensions: 1024,
        );

        expect($response->embeddings)->toHaveCount(2);
        expect($response->embeddings[0])->toBe($vector1);
        expect($response->embeddings[1])->toBe($vector2);
    });

    test('sends batch request with texts array', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello world', 'Foo bar'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['texts'] === ['Hello world', 'Foo bar']
                && $body['input_type'] === 'search_document'
                && $body['embedding_types'] === ['float'];
        });
    });

    test('sends single HTTP request for multiple inputs', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Text one', 'Text two', 'Text three'],
            dimensions: 1024,
        );

        Http::assertSentCount(1);
    });

    test('does not include output_dimension for v3 models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello'],
            dimensions: 512,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ! array_key_exists('output_dimension', $body);
        });
    });

    test('sends request to correct Bedrock URL for Cohere model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-east-1.amazonaws.com/model/cohere.embed-english-v3/invoke'
            );
        });
    });

    test('meta contains provider name and Cohere model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello'],
            dimensions: 1024,
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('cohere.embed-english-v3');
    });

    test('works with multilingual model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-multilingual-v3',
            inputs: ['Hello', 'こんにちは'],
            dimensions: 1024,
        );

        expect($response)->toBeInstanceOf(EmbeddingsResponse::class);
        expect($response->embeddings)->toHaveCount(2);
    });

    test('tokens is zero for Cohere models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-english-v3',
            inputs: ['Hello', 'World'],
            dimensions: 1024,
        );

        expect($response->tokens)->toBe(0);
    });
});

describe('BedrockGateway generateEmbeddings (Cohere Embed v4)', function () {
    test('includes output_dimension for v4 model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeCohereEmbeddingResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-v4',
            inputs: ['Hello'],
            dimensions: 512,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['output_dimension'] === 512
                && $body['texts'] === ['Hello']
                && $body['input_type'] === 'search_document'
                && $body['embedding_types'] === ['float'];
        });
    });

    test('returns embeddings from v4 response', function () {
        $vector1 = [0.01, -0.02, 0.03];

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeCohereEmbeddingResponse([$vector1]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'cohere.embed-v4',
            inputs: ['Hello'],
            dimensions: 512,
        );

        expect($response->embeddings)->toHaveCount(1);
        expect($response->first())->toBe($vector1);
    });
});

describe('Cohere model detection', function () {
    test('detects Cohere Embed English v3 as Cohere model', function () {
        $gateway = new BedrockGateway;
        $ref = new ReflectionMethod($gateway, 'isCohereEmbedModel');

        expect($ref->invoke($gateway, 'cohere.embed-english-v3'))->toBeTrue();
    });

    test('detects Cohere Embed Multilingual v3 as Cohere model', function () {
        $gateway = new BedrockGateway;
        $ref = new ReflectionMethod($gateway, 'isCohereEmbedModel');

        expect($ref->invoke($gateway, 'cohere.embed-multilingual-v3'))->toBeTrue();
    });

    test('detects Cohere Embed v4 as Cohere model', function () {
        $gateway = new BedrockGateway;
        $ref = new ReflectionMethod($gateway, 'isCohereEmbedModel');

        expect($ref->invoke($gateway, 'cohere.embed-v4'))->toBeTrue();
    });

    test('does not detect Titan as Cohere model', function () {
        $gateway = new BedrockGateway;
        $ref = new ReflectionMethod($gateway, 'isCohereEmbedModel');

        expect($ref->invoke($gateway, 'amazon.titan-embed-text-v2:0'))->toBeFalse();
    });

    test('detects v4 model correctly', function () {
        $gateway = new BedrockGateway;
        $ref = new ReflectionMethod($gateway, 'isCohereEmbedV4');

        expect($ref->invoke($gateway, 'cohere.embed-v4'))->toBeTrue();
        expect($ref->invoke($gateway, 'cohere.embed-english-v3'))->toBeFalse();
    });
});

describe('BedrockProvider embeddings defaults', function () {
    test('returns default embeddings model', function () {
        $provider = makeProvider();
        expect($provider->defaultEmbeddingsModel())->toBe('amazon.titan-embed-text-v2:0');
    });

    test('returns default embeddings dimensions', function () {
        $provider = makeProvider();
        expect($provider->defaultEmbeddingsDimensions())->toBe(1024);
    });

    test('uses configured embeddings model', function () {
        $provider = makeProvider([
            'models' => ['embeddings' => ['default' => 'cohere.embed-english-v3']],
        ]);
        expect($provider->defaultEmbeddingsModel())->toBe('cohere.embed-english-v3');
    });

    test('uses configured embeddings dimensions', function () {
        $provider = makeProvider([
            'models' => ['embeddings' => ['dimensions' => 512]],
        ]);
        expect($provider->defaultEmbeddingsDimensions())->toBe(512);
    });
});
