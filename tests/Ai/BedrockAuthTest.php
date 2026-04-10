<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

function fakeEmbeddingResponseForAuth(array $embedding = [], int $tokenCount = 5): array
{
    return [
        'embedding' => $embedding ?: [0.1, 0.2, 0.3],
        'inputTextTokenCount' => $tokenCount,
    ];
}

describe('Authentication modes', function () {
    test('uses bearer token when only key is configured', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider(['key' => 'my-bedrock-api-key']),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            return count($auth) > 0 && str_starts_with($auth[0], 'Bearer my-bedrock-api-key');
        });
    });

    test('does not use bearer token when secret is configured', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider([
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ]),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            // Bearer token should NOT be set when using SigV4
            return empty($auth) || ! str_starts_with($auth[0] ?? '', 'Bearer');
        });
    });

    test('sends request to correct URL with SigV4 auth', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider([
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            ]),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-east-1.amazonaws.com/model/amazon.titan-embed-text-v2:0/invoke',
            );
        });
    });

    test('uses configured region with SigV4 auth', function () {
        Http::fake([
            'bedrock-runtime.ap-northeast-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider([
                'key' => 'AKIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'region' => 'ap-northeast-1',
            ]),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.ap-northeast-1.amazonaws.com');
        });
    });

    test('SigV4 with session token does not use bearer', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateEmbeddings(
            provider: makeProvider([
                'key' => 'ASIAIOSFODNN7EXAMPLE',
                'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
                'token' => 'FwoGZXIvYXdzEBYaDHqa0AP',
            ]),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        Http::assertSent(function ($request) {
            $auth = $request->header('Authorization');

            return empty($auth) || ! str_starts_with($auth[0] ?? '', 'Bearer');
        });
    });

    test('falls back to default credential chain when no key or secret', function () {
        // With no AWS credentials in the environment, the default provider will throw
        expect(function () {
            Http::fake([
                'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
            ]);

            $gateway = new BedrockGateway;
            $gateway->generateEmbeddings(
                provider: makeProvider(['key' => null]),
                model: 'amazon.titan-embed-text-v2:0',
                inputs: ['Hello world'],
                dimensions: 1024,
            );
        })->toThrow(Exception::class);
    });

    test('empty key string falls back to default credential chain', function () {
        expect(function () {
            Http::fake([
                'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
            ]);

            $gateway = new BedrockGateway;
            $gateway->generateEmbeddings(
                provider: makeProvider(['key' => '']),
                model: 'amazon.titan-embed-text-v2:0',
                inputs: ['Hello world'],
                dimensions: 1024,
            );
        })->toThrow(Exception::class);
    });

    test('bearer auth preserves existing behavior', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeEmbeddingResponseForAuth()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello world'],
            dimensions: 1024,
        );

        expect($response->embeddings)->toHaveCount(1);
        expect($response->first())->toBe([0.1, 0.2, 0.3]);

        Http::assertSent(function ($request) {
            return str_starts_with($request->header('Authorization')[0] ?? '', 'Bearer test-api-key');
        });
    });
});
