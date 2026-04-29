<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

// makeProvider() is defined in BedrockGatewayTest.php and available globally via Pest.

describe('HandlesFailoverErrors', function () {
    test('throws RateLimitedException on 429 for text generation', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Too many requests'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(RateLimitedException::class);

    test('throws ProviderOverloadedException on 503', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Service unavailable'],
                503,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(ProviderOverloadedException::class);

    test('throws ProviderOverloadedException on 529 (Anthropic overloaded)', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Overloaded'],
                529,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(ProviderOverloadedException::class);

    test('throws RateLimitedException on 429 for embeddings', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateEmbeddings(
            provider: makeProvider(),
            model: 'amazon.titan-embed-text-v2:0',
            inputs: ['Hello'],
            dimensions: 1024,
        );
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for image generation', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
        );
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for audio generation', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateAudio(
            provider: makeProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'Ruth',
        );
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for transcription', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $audio = new class implements TranscribableAudio
        {
            public function content(): string
            {
                return 'fake-audio-content';
            }

            public function mimeType(): string
            {
                return 'audio/mp3';
            }

            public function withMimeType(string $mimeType): static
            {
                return new self;
            }

            public function transcription(): PendingTranscriptionGeneration
            {
                return new PendingTranscriptionGeneration($this);
            }

            public function __toString(): string
            {
                return 'fake-audio';
            }
        };

        $gateway = new BedrockGateway;

        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: $audio,
        );
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for reranking', function () {
        Http::fake([
            'bedrock-agent-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->rerank(
            provider: makeProvider(),
            model: 'cohere.rerank-v3-5:0',
            documents: ['doc1', 'doc2'],
            query: 'test query',
        );
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for Converse API text generation', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Too many requests'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(RateLimitedException::class);

    test('throws ProviderOverloadedException on 503 for Converse API', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Service unavailable'],
                503,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(ProviderOverloadedException::class);

    test('throws RateLimitedException on 429 for streaming', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Too many requests'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $generator = $gateway->streamText(
            invocationId: 'test-id',
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );

        // Must iterate to trigger the HTTP call
        iterator_to_array($generator);
    })->throws(RateLimitedException::class);

    test('throws RateLimitedException on 429 for Converse API streaming', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Too many requests'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        $generator = $gateway->streamText(
            invocationId: 'test-id',
            provider: makeProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );

        iterator_to_array($generator);
    })->throws(RateLimitedException::class);

    test('overloadedStatusCodes includes both 503 and 529', function () {
        $gateway = new BedrockGateway;

        $reflection = new ReflectionMethod($gateway, 'overloadedStatusCodes');
        $codes = $reflection->invoke($gateway);

        expect($codes)->toBe([503, 529]);
    });

    test('non-failover HTTP errors are re-thrown as RequestException', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Bad request'],
                400,
            ),
        ]);

        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [['role' => 'user', 'content' => 'Hi']],
        );
    })->throws(RequestException::class);

    test('exception message includes provider name for rate limiting', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Throttled'],
                429,
            ),
        ]);

        $gateway = new BedrockGateway;

        try {
            $gateway->generateText(
                provider: makeProvider(),
                model: 'anthropic.claude-3-haiku-20240307-v1:0',
                instructions: null,
                messages: [['role' => 'user', 'content' => 'Hi']],
            );
        } catch (RateLimitedException $e) {
            expect($e->getMessage())->toContain('bedrock');

            return;
        }

        $this->fail('Expected RateLimitedException was not thrown');
    });

    test('exception message includes provider name for overload', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                ['message' => 'Overloaded'],
                529,
            ),
        ]);

        $gateway = new BedrockGateway;

        try {
            $gateway->generateText(
                provider: makeProvider(),
                model: 'anthropic.claude-3-haiku-20240307-v1:0',
                instructions: null,
                messages: [['role' => 'user', 'content' => 'Hi']],
            );
        } catch (ProviderOverloadedException $e) {
            expect($e->getMessage())->toContain('bedrock');

            return;
        }

        $this->fail('Expected ProviderOverloadedException was not thrown');
    });
});
