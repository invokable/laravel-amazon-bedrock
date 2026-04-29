<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Responses\TextResponse;
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
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['text' => $text],
                ],
            ],
        ],
        'stopReason' => 'end_turn',
        'usage' => array_merge([
            'inputTokens' => 10,
            'outputTokens' => 20,
            'cacheWriteInputTokens' => 5,
            'cacheReadInputTokens' => 2,
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
                    'inputTokens' => 15,
                    'outputTokens' => 30,
                    'cacheWriteInputTokens' => 8,
                    'cacheReadInputTokens' => 3,
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

    test('sends request to correct bedrock converse api url', function () {
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
                && str_contains($request->url(), '/converse');
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

    test('sends instructions as system prompt with cachePoint', function () {
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
            return $request['system'][0]['text'] === 'You are a helpful assistant.'
                && $request['system'][1]['cachePoint']['type'] === 'default';
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
            return $request['inferenceConfig']['maxTokens'] === 512;
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
            return $request['inferenceConfig']['temperature'] === 0.7;
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
            return $request['inferenceConfig']['maxTokens'] === 1024;
        });
    });

    test('does not send anthropic_version', function () {
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
            return ! isset($request['anthropic_version']);
        });
    });

    test('ignores anthropic_version from providerOptions', function () {
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
            return ! isset($request['anthropic_version'])
                && ! isset($request['additionalModelRequestFields']['anthropic_version']);
        });
    });

    test('ignores anthropic_version from provider config', function () {
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
            return ! isset($request['anthropic_version']);
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
            return $request['additionalModelRequestFields']['top_k'] === 40
                && $request['additionalModelRequestFields']['top_p'] === 0.9;
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

describe('BedrockProvider model defaults', function () {
    test('returns default text model', function () {
        $provider = makeProvider();

        expect($provider->defaultTextModel())->toBe('global.anthropic.claude-sonnet-4-6');
    });

    test('returns cheapest text model', function () {
        $provider = makeProvider();

        expect($provider->cheapestTextModel())->toBe('global.anthropic.claude-haiku-4-5-20251001-v1:0');
    });

    test('returns smartest text model', function () {
        $provider = makeProvider();

        expect($provider->smartestTextModel())->toBe('global.anthropic.claude-opus-4-7');
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
