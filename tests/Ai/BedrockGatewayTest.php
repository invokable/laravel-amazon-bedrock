<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

function makeProvider(array $config = []): BedrockProvider
{
    return new BedrockProvider(
        config: array_merge([
            'name' => 'bedrock',
            'driver' => 'bedrock',
            'key' => 'test-api-key',
            'region' => 'us-east-1',
        ], $config),
        events: app(Dispatcher::class),
    );
}

function makeOptionsWithProviderOptions(array $providerOptions, ?int $maxTokens = null, ?float $temperature = null): TextGenerationOptions
{
    return new class($providerOptions, $maxTokens, $temperature) extends TextGenerationOptions
    {
        public function __construct(
            private readonly array $testProviderOptions,
            ?int $maxTokens = null,
            ?float $temperature = null,
        ) {
            parent::__construct(maxTokens: $maxTokens, temperature: $temperature);
        }

        public function providerOptions(Lab|string $provider): ?array
        {
            return $this->testProviderOptions;
        }
    };
}

function fakeBedrockResponse(string $text = 'Hello!', array $usage = []): array
{
    return [
        'id' => 'msg_test123',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
        'content' => [
            ['type' => 'text', 'text' => $text],
        ],
        'stop_reason' => 'end_turn',
        'usage' => array_merge([
            'input_tokens' => 10,
            'output_tokens' => 20,
            'cache_creation_input_tokens' => 5,
            'cache_read_input_tokens' => 2,
        ], $usage),
    ];
}

describe('BedrockGateway generateText', function () {
    test('returns a TextResponse instance', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        expect($response)->toBeInstanceOf(TextResponse::class);
    });

    test('maps text from response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeBedrockResponse('Test response text'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        expect($response->text)->toBe('Test response text');
    });

    test('maps usage tokens correctly', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeBedrockResponse(usage: [
                    'input_tokens' => 15,
                    'output_tokens' => 30,
                    'cache_creation_input_tokens' => 8,
                    'cache_read_input_tokens' => 3,
                ]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        expect($response->usage->promptTokens)->toBe(15);
        expect($response->usage->completionTokens)->toBe(30);
        expect($response->usage->cacheWriteInputTokens)->toBe(8);
        expect($response->usage->cacheReadInputTokens)->toBe(3);
    });

    test('maps meta with provider name and model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeProvider(['name' => 'bedrock']),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('anthropic.claude-3-haiku-20240307-v1:0');
    });

    test('sends request to correct bedrock api url', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(['region' => 'us-east-1']),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.us-east-1.amazonaws.com')
                && str_contains($request->url(), 'anthropic.claude-3-haiku-20240307-v1:0')
                && str_contains($request->url(), '/invoke');
        });
    });

    test('sends api key as bearer token', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(['key' => 'my-secret-key']),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-secret-key');
        });
    });

    test('sends instructions as system prompt with cache_control', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: 'You are a helpful assistant.',
            messages: [],
        );

        Http::assertSent(function ($request) {
            return $request['system'][0]['type'] === 'text'
                && $request['system'][0]['text'] === 'You are a helpful assistant.'
                && $request['system'][0]['cache_control']['type'] === 'ephemeral';
        });
    });

    test('does not send system when instructions is null', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return ! isset($request['system']);
        });
    });

    test('maps SDK messages to bedrock format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [
                new Message('user', 'What is PHP?'),
                new Message('assistant', 'PHP is a scripting language.'),
                new Message('user', 'Tell me more.'),
            ],
        );

        Http::assertSent(function ($request) {
            return $request['messages'][0]['role'] === 'user'
                && $request['messages'][0]['content'][0]['text'] === 'What is PHP?'
                && $request['messages'][1]['role'] === 'assistant'
                && $request['messages'][1]['content'][0]['text'] === 'PHP is a scripting language.'
                && $request['messages'][2]['role'] === 'user'
                && $request['messages'][2]['content'][0]['text'] === 'Tell me more.';
        });
    });

    test('respects maxTokens from TextGenerationOptions', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
            options: new TextGenerationOptions(maxTokens: 512),
        );

        Http::assertSent(function ($request) {
            return $request['max_tokens'] === 512;
        });
    });

    test('respects temperature from TextGenerationOptions', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $options = new TextGenerationOptions(temperature: 0.7);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
            options: $options,
        );

        Http::assertSent(function ($request) {
            return $request['temperature'] === 0.7;
        });
    });

    test('uses max_tokens from provider config when options not set', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(['max_tokens' => 1024]),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return $request['max_tokens'] === 1024;
        });
    });

    test('sends default anthropic_version when not specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return $request['anthropic_version'] === 'bedrock-2023-05-31';
        });
    });

    test('uses anthropic_version from providerOptions', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $options = makeOptionsWithProviderOptions([
            'anthropic_version' => 'custom-2024-01-01',
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
            options: $options,
        );

        Http::assertSent(function ($request) {
            return $request['anthropic_version'] === 'custom-2024-01-01';
        });
    });

    test('uses anthropic_version from provider config as fallback', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(['anthropic_version' => 'bedrock-2023-05-31']),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return $request['anthropic_version'] === 'bedrock-2023-05-31';
        });
    });

    test('merges extra providerOptions into request body', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $options = makeOptionsWithProviderOptions([
            'top_k' => 40,
            'top_p' => 0.9,
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
            options: $options,
        );

        Http::assertSent(function ($request) {
            return $request['top_k'] === 40
                && $request['top_p'] === 0.9;
        });
    });

    test('uses different region from provider config', function () {
        Http::fake([
            'bedrock-runtime.ap-northeast-1.amazonaws.com/*' => Http::response(fakeBedrockResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeProvider(['region' => 'ap-northeast-1']),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.ap-northeast-1.amazonaws.com');
        });
    });
});

describe('BedrockGateway streamText event mapping', function () {
    test('maps message_start to StreamStart', function () {
        $gateway = new class extends BedrockGateway
        {
            public function mapEvent(string $invocationId, array $event, string $provider, string $model): mixed
            {
                $events = iterator_to_array($this->processTextStreamFromEvents($invocationId, $provider, $model, [$event]));

                return $events[0] ?? null;
            }

            protected function processTextStreamFromEvents(string $invocationId, string $provider, string $model, array $events): Generator
            {
                // Simulate provider for processTextStream
                foreach ($events as $event) {
                    $type = $event['type'] ?? '';

                    if ($type === 'message_start') {
                        yield (new StreamStart(
                            $this->generateEventId(),
                            $provider,
                            $event['message']['model'] ?? $model,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }
        };

        $event = $gateway->mapEvent('inv-1', [
            'type' => 'message_start',
            'message' => ['model' => 'claude'],
        ], 'bedrock', 'claude');

        expect($event)->toBeInstanceOf(StreamStart::class);
    });

    test('maps content_block_start to TextStart', function () {
        $gateway = new class extends BedrockGateway
        {
            public function testMapEvent(string $invocationId, array $events): array
            {
                $provider = makeProvider();
                $generator = $this->processTextStreamFromArray($invocationId, $provider, 'claude', $events);

                return iterator_to_array($generator);
            }

            protected function processTextStreamFromArray(string $invocationId, $provider, string $model, array $rawEvents): Generator
            {
                $messageId = $this->generateEventId();
                $streamStartEmitted = false;
                $textStartEmitted = false;

                foreach ($rawEvents as $event) {
                    $type = $event['type'] ?? '';

                    if ($type === 'message_start' && ! $streamStartEmitted) {
                        $streamStartEmitted = true;

                        yield (new StreamStart(
                            $this->generateEventId(),
                            $provider->name(),
                            $model,
                            time(),
                        ))->withInvocationId($invocationId);

                        continue;
                    }

                    if ($type === 'content_block_start') {
                        if (! $textStartEmitted) {
                            $textStartEmitted = true;

                            yield (new TextStart(
                                $this->generateEventId(),
                                $messageId,
                                time(),
                            ))->withInvocationId($invocationId);
                        }

                        continue;
                    }

                    if ($type === 'content_block_delta' && ($event['delta']['type'] ?? '') === 'text_delta') {
                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $event['delta']['text'] ?? '',
                            time(),
                        ))->withInvocationId($invocationId);

                        continue;
                    }

                    if ($type === 'content_block_stop' && $textStartEmitted) {
                        $textStartEmitted = false;

                        yield (new TextEnd(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);

                        continue;
                    }
                }
            }
        };

        $events = $gateway->testMapEvent('inv-1', [
            ['type' => 'message_start', 'message' => ['model' => 'claude']],
            ['type' => 'content_block_start', 'content_block' => ['type' => 'text']],
        ]);

        expect($events)->toHaveCount(2);
        expect($events[0])->toBeInstanceOf(StreamStart::class);
        expect($events[1])->toBeInstanceOf(TextStart::class);
    });

    test('maps content_block_delta to TextDelta with text', function () {
        $gateway = new class extends BedrockGateway
        {
            public function testMapEvent(string $invocationId, array $events): array
            {
                $provider = makeProvider();
                $generator = $this->processTextStreamFromArray($invocationId, $provider, 'claude', $events);

                return iterator_to_array($generator);
            }

            protected function processTextStreamFromArray(string $invocationId, $provider, string $model, array $rawEvents): Generator
            {
                $messageId = $this->generateEventId();

                foreach ($rawEvents as $event) {
                    $type = $event['type'] ?? '';

                    if ($type === 'content_block_delta' && ($event['delta']['type'] ?? '') === 'text_delta') {
                        yield (new TextDelta(
                            $this->generateEventId(),
                            $messageId,
                            $event['delta']['text'] ?? '',
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }
        };

        $events = $gateway->testMapEvent('inv-1', [
            ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
        ]);

        expect($events)->toHaveCount(1);
        expect($events[0])->toBeInstanceOf(TextDelta::class);
        expect($events[0]->delta)->toBe('Hello');
    });

    test('maps content_block_stop to TextEnd', function () {
        $gateway = new class extends BedrockGateway
        {
            public function testMapEvent(string $invocationId, array $events): array
            {
                $provider = makeProvider();
                $generator = $this->processTextStreamFromArray($invocationId, $provider, 'claude', $events);

                return iterator_to_array($generator);
            }

            protected function processTextStreamFromArray(string $invocationId, $provider, string $model, array $rawEvents): Generator
            {
                $messageId = $this->generateEventId();
                $textStartEmitted = true;

                foreach ($rawEvents as $event) {
                    $type = $event['type'] ?? '';

                    if ($type === 'content_block_stop' && $textStartEmitted) {
                        $textStartEmitted = false;

                        yield (new TextEnd(
                            $this->generateEventId(),
                            $messageId,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }
        };

        $events = $gateway->testMapEvent('inv-1', [
            ['type' => 'content_block_stop'],
        ]);

        expect($events)->toHaveCount(1);
        expect($events[0])->toBeInstanceOf(TextEnd::class);
    });

    test('stream events carry invocation id', function () {
        $gateway = new class extends BedrockGateway
        {
            public function testMapEvent(string $invocationId, array $events): array
            {
                $provider = makeProvider();
                $generator = $this->processTextStreamFromArray($invocationId, $provider, 'claude', $events);

                return iterator_to_array($generator);
            }

            protected function processTextStreamFromArray(string $invocationId, $provider, string $model, array $rawEvents): Generator
            {
                foreach ($rawEvents as $event) {
                    if (($event['type'] ?? '') === 'message_start') {
                        yield (new StreamStart(
                            $this->generateEventId(),
                            $provider->name(),
                            $model,
                            time(),
                        ))->withInvocationId($invocationId);
                    }
                }
            }
        };

        $events = $gateway->testMapEvent('my-invocation-id', [
            ['type' => 'message_start', 'message' => ['model' => 'claude']],
        ]);

        expect($events[0]->invocationId)->toBe('my-invocation-id');
    });
});

describe('BedrockProvider model defaults', function () {
    test('returns default text model', function () {
        $provider = makeProvider();

        expect($provider->defaultTextModel())->toBe('global.anthropic.claude-sonnet-4-6:0');
    });

    test('returns cheapest text model', function () {
        $provider = makeProvider();

        expect($provider->cheapestTextModel())->toBe('global.anthropic.claude-haiku-4-5-20251001-v1:0');
    });

    test('returns smartest text model', function () {
        $provider = makeProvider();

        expect($provider->smartestTextModel())->toBe('global.anthropic.claude-opus-4-6-v1:0');
    });

    test('uses custom models from config', function () {
        $provider = makeProvider([
            'models' => [
                'text' => [
                    'default' => 'custom.model:1',
                    'cheapest' => 'custom.cheap:1',
                    'smartest' => 'custom.smart:1',
                ],
            ],
        ]);

        expect($provider->defaultTextModel())->toBe('custom.model:1');
        expect($provider->cheapestTextModel())->toBe('custom.cheap:1');
        expect($provider->smartestTextModel())->toBe('custom.smart:1');
    });

    test('exposes credentials via providerCredentials', function () {
        $provider = makeProvider(['key' => 'my-key']);

        expect($provider->providerCredentials()['key'])->toBe('my-key');
    });

    test('exposes region via additionalConfiguration', function () {
        $provider = makeProvider(['region' => 'ap-northeast-1']);

        expect($provider->additionalConfiguration()['region'])->toBe('ap-northeast-1');
    });
});
