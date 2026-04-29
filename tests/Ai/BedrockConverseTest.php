<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Tools\Request;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

function makeConverseProvider(array $config = []): BedrockProvider
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

function makeConverseOptions(array $providerOptions = [], ?int $maxTokens = null, ?float $temperature = null): TextGenerationOptions
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

function fakeConverseResponse(string $text = 'Hello!', array $usage = []): array
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
            'totalTokens' => 30,
        ], $usage),
        'metrics' => [
            'latencyMs' => 500,
        ],
    ];
}

function makeMockConverseStreamGateway(array $events): object
{
    return new class($events) extends BedrockGateway
    {
        public array $events = [];

        public function __construct(array $events)
        {
            parent::__construct();
            $this->events = $events;
        }

        protected function decodeConverseEventStream($streamBody): Generator
        {
            foreach ($this->events as $event) {
                yield $event;
            }
        }

        public function testProcessConverseStream(
            string $invocationId,
            Provider $provider,
            string $model,
            array $tools,
            ?TextGenerationOptions $options,
            $streamBody,
        ): Generator {
            return $this->processConverseStream(
                invocationId: $invocationId,
                provider: $provider,
                model: $model,
                tools: $tools,
                options: $options,
                streamBody: $streamBody,
            );
        }
    };
}

function fakeConverseToolUseResponse(): array
{
    return [
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Let me get the weather.'],
                    [
                        'toolUse' => [
                            'toolUseId' => 'tool_123',
                            'name' => 'GetWeather',
                            'input' => ['city' => 'Tokyo'],
                        ],
                    ],
                ],
            ],
        ],
        'stopReason' => 'tool_use',
        'usage' => [
            'inputTokens' => 15,
            'outputTokens' => 25,
            'totalTokens' => 40,
        ],
    ];
}

describe('DetectsModelApi', function () {
    test('identifies Anthropic models correctly', function () {
        $gateway = new BedrockGateway;

        $ref = new ReflectionMethod($gateway, 'isAnthropicModel');

        expect($ref->invoke($gateway, 'anthropic.claude-3-haiku-20240307-v1:0'))->toBeTrue()
            ->and($ref->invoke($gateway, 'global.anthropic.claude-sonnet-4-6:0'))->toBeTrue()
            ->and($ref->invoke($gateway, 'amazon.nova-pro-v1:0'))->toBeFalse()
            ->and($ref->invoke($gateway, 'meta.llama3-8b-instruct-v1:0'))->toBeFalse()
            ->and($ref->invoke($gateway, 'mistral.mistral-large-2402-v1:0'))->toBeFalse()
            ->and($ref->invoke($gateway, 'cohere.command-r-plus-v1:0'))->toBeFalse();
    });

    test('routes non-Anthropic models to Converse API', function () {
        $gateway = new BedrockGateway;

        $ref = new ReflectionMethod($gateway, 'useConverseApi');

        expect($ref->invoke($gateway, 'amazon.nova-pro-v1:0'))->toBeTrue()
            ->and($ref->invoke($gateway, 'meta.llama3-8b-instruct-v1:0'))->toBeTrue()
            ->and($ref->invoke($gateway, 'anthropic.claude-3-haiku-20240307-v1:0'))->toBeFalse();
    });

    test('generates correct Converse API URLs', function () {
        $gateway = new BedrockGateway;

        $converseUrl = new ReflectionMethod($gateway, 'converseUrl');
        $converseStreamUrl = new ReflectionMethod($gateway, 'converseStreamUrl');

        expect($converseUrl->invoke($gateway, 'amazon.nova-pro-v1:0'))
            ->toBe('model/amazon.nova-pro-v1:0/converse')
            ->and($converseStreamUrl->invoke($gateway, 'amazon.nova-pro-v1:0'))
            ->toBe('model/amazon.nova-pro-v1:0/converse-stream');
    });
});

describe('Converse API generateText', function () {
    test('returns a TextResponse for Amazon Nova models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response)->toBeInstanceOf(TextResponse::class);
    });

    test('maps text from Converse response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseResponse('This is a Nova response'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->text)->toBe('This is a Nova response');
    });

    test('maps usage tokens from Converse response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseResponse(usage: [
                    'inputTokens' => 15,
                    'outputTokens' => 30,
                    'totalTokens' => 45,
                ]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->usage->promptTokens)->toBe(15)
            ->and($response->usage->completionTokens)->toBe(30);
    });

    test('sends request to /converse endpoint', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/model/amazon.nova-pro-v1:0/converse');
        });
    });

    test('includes system prompt in Converse format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: 'You are a helpful assistant.',
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['system'])
                && $body['system'][0]['text'] === 'You are a helpful assistant.';
        });
    });

    test('maps messages to Converse format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [
                new Message('user', 'What is PHP?'),
            ],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['messages'][0]['role'] === 'user'
                && $body['messages'][0]['content'][0]['text'] === 'What is PHP?';
        });
    });

    test('includes inferenceConfig with maxTokens and temperature', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
            options: makeConverseOptions(maxTokens: 500, temperature: 0.7),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['inferenceConfig']['maxTokens'] ?? null) === 500
                && ($body['inferenceConfig']['temperature'] ?? null) === 0.7;
        });
    });

    test('does not include anthropic_version in Converse requests', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ! isset($body['anthropic_version']);
        });
    });

    test('works with Meta Llama models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseResponse('Llama response'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'meta.llama3-8b-instruct-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->text)->toBe('Llama response');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/model/meta.llama3-8b-instruct-v1:0/converse');
        });
    });

    test('works with Mistral models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseResponse('Mistral response'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'mistral.mistral-large-2402-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->text)->toBe('Mistral response');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/model/mistral.mistral-large-2402-v1:0/converse');
        });
    });

    test('still uses Anthropic API for Claude models', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_test123',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
                'content' => [
                    ['type' => 'text', 'text' => 'Claude response'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                ],
            ]),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->text)->toBe('Claude response');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/model/anthropic.claude-3-haiku-20240307-v1:0/invoke')
                && ! str_contains($request->url(), '/converse');
        });
    });
});

describe('Converse API finish reasons', function () {
    test('maps end_turn to Stop', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::Stop);
    });

    test('maps max_tokens to Length', function () {
        $data = fakeConverseResponse();
        $data['stopReason'] = 'max_tokens';

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response($data),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::Length);
    });

    test('maps tool_use to ToolCalls', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseToolUseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'What is the weather?')],
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::ToolCalls);
    });
});

describe('Converse API tool calls', function () {
    test('extracts tool calls from Converse response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseToolUseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'What is the weather?')],
        );

        $toolCalls = $response->steps->first()->toolCalls;

        expect($toolCalls)->toHaveCount(1)
            ->and($toolCalls[0]->name)->toBe('GetWeather')
            ->and($toolCalls[0]->arguments)->toBe(['city' => 'Tokyo'])
            ->and($toolCalls[0]->id)->toBe('tool_123');
    });

    test('maps tools to Converse toolSpec format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $tool = new class implements Tool
        {
            public function description(): string
            {
                return 'A test tool';
            }

            public function schema(JsonSchema $schema): array
            {
                return [
                    'query' => $schema->string('The query'),
                ];
            }

            public function handle(Request $request): string
            {
                return 'result';
            }
        };

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Test')],
            tools: [$tool],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['toolConfig']['tools'][0]['toolSpec'])
                && $body['toolConfig']['tools'][0]['toolSpec']['description'] === 'A test tool'
                && isset($body['toolConfig']['tools'][0]['toolSpec']['inputSchema']['json']);
        });
    });
});

describe('Converse API structured output', function () {
    test('returns StructuredTextResponse for schema requests', function () {
        $data = fakeConverseResponse();
        $data['output']['message']['content'] = [
            [
                'toolUse' => [
                    'toolUseId' => 'struct_123',
                    'name' => 'output_structured_data',
                    'input' => ['name' => 'John', 'age' => 30],
                ],
            ],
        ];
        $data['stopReason'] = 'tool_use';

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response($data),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Give me a person')],
            schema: [
                'name' => (new JsonSchemaTypeFactory)->string('The name'),
                'age' => (new JsonSchemaTypeFactory)->integer('The age'),
            ],
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response['name'])->toBe('John')
            ->and($response['age'])->toBe(30);
    });

    test('includes structured output tool in toolConfig', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Give me a person')],
            schema: [
                'name' => (new JsonSchemaTypeFactory)->string('The name'),
            ],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            $tools = $body['toolConfig']['tools'] ?? [];
            $hasStructuredTool = false;

            foreach ($tools as $tool) {
                if (($tool['toolSpec']['name'] ?? '') === 'output_structured_data') {
                    $hasStructuredTool = true;
                }
            }

            $toolChoice = $body['toolConfig']['toolChoice'] ?? [];

            return $hasStructuredTool
                && isset($toolChoice['tool'])
                && $toolChoice['tool']['name'] === 'output_structured_data';
        });
    });
});

describe('Converse API request format', function () {
    test('does not include system when instructions are null', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ! isset($body['system']);
        });
    });

    test('uses correct region from config', function () {
        Http::fake([
            'bedrock-runtime.eu-west-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(['region' => 'eu-west-1']),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.eu-west-1.amazonaws.com');
        });
    });

    test('uses max_tokens from config for Converse inferenceConfig', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(['max_tokens' => 4096]),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['inferenceConfig']['maxTokens'] ?? null) === 4096;
        });
    });

    test('maps image attachments to Converse image blocks', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new UserMessage('Describe this image', [
                Image::fromBase64(base64_encode('fake-image'), 'image/png'),
            ])],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];

            return ($content[0]['image']['format'] ?? null) === 'png'
                && ($content[0]['image']['source']['bytes'] ?? null) === base64_encode('fake-image')
                && ($content[1]['text'] ?? null) === 'Describe this image';
        });
    });

    test('maps document attachments to Converse document blocks with safe names', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new UserMessage('Summarize this document', [
                Document::fromString('document text', 'text/plain')->as('Quarterly_Report!.txt'),
            ])],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $document = $body['messages'][0]['content'][0]['document'] ?? [];

            return ($document['format'] ?? null) === 'txt'
                && ($document['name'] ?? null) === 'Quarterly Report'
                && ($document['source']['bytes'] ?? null) === base64_encode('document text');
        });
    });

    test('maps audio attachments to Converse audio blocks', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new UserMessage('Transcribe then answer', [
                Audio::fromBase64(base64_encode('fake-audio'), 'audio/webm'),
            ])],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $audio = $body['messages'][0]['content'][0]['audio'] ?? [];

            return ($audio['format'] ?? null) === 'webm'
                && ($audio['source']['bytes'] ?? null) === base64_encode('fake-audio');
        });
    });

    test('maps uploaded video attachments to Converse video blocks', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseResponse()),
        ]);

        $video = UploadedFile::fake()->create('demo.mp4', 1, 'video/mp4');

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new UserMessage('Describe this video', [$video])],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $video = $body['messages'][0]['content'][0]['video'] ?? [];

            return ($video['format'] ?? null) === 'mp4'
                && isset($video['source']['bytes']);
        });
    });

    test('rejects unsupported provider file attachments for Converse', function () {
        $gateway = new BedrockGateway;

        $gateway->generateText(
            provider: makeConverseProvider(),
            model: 'amazon.nova-pro-v1:0',
            instructions: null,
            messages: [new UserMessage('Describe this image', [Image::fromId('file_123')])],
        );
    })->throws(InvalidArgumentException::class, 'Unsupported attachment type ['.ProviderImage::class.']');
});

describe('Converse stream event parsing', function () {
    test('maps messageStart to StreamStart', function () {
        $events = [
            ['messageStart', ['role' => 'assistant']],
            ['contentBlockStart', ['contentBlockIndex' => 0, 'start' => []]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hello']]],
            ['contentBlockStop', ['contentBlockIndex' => 0]],
            ['messageStop', ['stopReason' => 'end_turn']],
            ['metadata', ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]],
        ];

        $mockGateway = makeMockConverseStreamGateway($events);
        $provider = makeConverseProvider();

        $streamEvents = iterator_to_array($mockGateway->testProcessConverseStream(
            invocationId: 'test-inv-123',
            provider: $provider,
            model: 'amazon.nova-pro-v1:0',
            tools: [],
            options: null,
            streamBody: null,
        ));

        expect($streamEvents[0])->toBeInstanceOf(StreamStart::class);
    });

    test('maps contentBlockDelta text to TextDelta', function () {
        $events = [
            ['messageStart', ['role' => 'assistant']],
            ['contentBlockStart', ['contentBlockIndex' => 0, 'start' => []]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hello ']]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'World']]],
            ['contentBlockStop', ['contentBlockIndex' => 0]],
            ['messageStop', ['stopReason' => 'end_turn']],
            ['metadata', ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]],
        ];

        $mockGateway = makeMockConverseStreamGateway($events);
        $provider = makeConverseProvider();

        $streamEvents = iterator_to_array($mockGateway->testProcessConverseStream(
            invocationId: 'test-inv-123',
            provider: $provider,
            model: 'amazon.nova-pro-v1:0',
            tools: [],
            options: null,
            streamBody: null,
        ));

        // StreamStart, TextStart, TextDelta('Hello '), TextDelta('World'), TextEnd, StreamEnd
        $textDeltas = array_values(array_filter($streamEvents, fn ($e) => $e instanceof TextDelta));

        expect($textDeltas)->toHaveCount(2)
            ->and($textDeltas[0]->delta)->toBe('Hello ')
            ->and($textDeltas[1]->delta)->toBe('World');
    });

    test('emits TextStart and TextEnd', function () {
        $events = [
            ['messageStart', ['role' => 'assistant']],
            ['contentBlockStart', ['contentBlockIndex' => 0, 'start' => []]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Test']]],
            ['contentBlockStop', ['contentBlockIndex' => 0]],
            ['messageStop', ['stopReason' => 'end_turn']],
            ['metadata', ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]],
        ];

        $mockGateway = makeMockConverseStreamGateway($events);
        $provider = makeConverseProvider();

        $streamEvents = iterator_to_array($mockGateway->testProcessConverseStream(
            invocationId: 'test-inv-123',
            provider: $provider,
            model: 'amazon.nova-pro-v1:0',
            tools: [],
            options: null,
            streamBody: null,
        ));

        $hasTextStart = false;
        $hasTextEnd = false;

        foreach ($streamEvents as $event) {
            if ($event instanceof TextStart) {
                $hasTextStart = true;
            }
            if ($event instanceof TextEnd) {
                $hasTextEnd = true;
            }
        }

        expect($hasTextStart)->toBeTrue()
            ->and($hasTextEnd)->toBeTrue();
    });

    test('emits StreamEnd with usage', function () {
        $events = [
            ['messageStart', ['role' => 'assistant']],
            ['contentBlockStart', ['contentBlockIndex' => 0, 'start' => []]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]],
            ['contentBlockStop', ['contentBlockIndex' => 0]],
            ['messageStop', ['stopReason' => 'end_turn']],
            ['metadata', ['usage' => ['inputTokens' => 12, 'outputTokens' => 8]]],
        ];

        $mockGateway = makeMockConverseStreamGateway($events);
        $provider = makeConverseProvider();

        $streamEvents = iterator_to_array($mockGateway->testProcessConverseStream(
            invocationId: 'test-inv-123',
            provider: $provider,
            model: 'amazon.nova-pro-v1:0',
            tools: [],
            options: null,
            streamBody: null,
        ));

        $streamEnd = end($streamEvents);

        expect($streamEnd)->toBeInstanceOf(StreamEnd::class)
            ->and($streamEnd->usage->promptTokens)->toBe(12)
            ->and($streamEnd->usage->completionTokens)->toBe(8);
    });

    test('propagates invocationId on all events', function () {
        $events = [
            ['messageStart', ['role' => 'assistant']],
            ['contentBlockStart', ['contentBlockIndex' => 0, 'start' => []]],
            ['contentBlockDelta', ['contentBlockIndex' => 0, 'delta' => ['text' => 'Hi']]],
            ['contentBlockStop', ['contentBlockIndex' => 0]],
            ['messageStop', ['stopReason' => 'end_turn']],
            ['metadata', ['usage' => ['inputTokens' => 10, 'outputTokens' => 5]]],
        ];

        $mockGateway = makeMockConverseStreamGateway($events);
        $provider = makeConverseProvider();

        $streamEvents = iterator_to_array($mockGateway->testProcessConverseStream(
            invocationId: 'my-unique-id',
            provider: $provider,
            model: 'amazon.nova-pro-v1:0',
            tools: [],
            options: null,
            streamBody: null,
        ));

        foreach ($streamEvents as $event) {
            expect($event->invocationId)->toBe('my-unique-id');
        }
    });
});
