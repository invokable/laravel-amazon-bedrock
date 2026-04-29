<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\Request;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

function makeToolTestProvider(array $config = []): BedrockProvider
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

function fakeToolUseResponse(string $toolCallId = 'toolu_01', string $toolName = 'GetWeather', array $input = ['city' => 'Tokyo']): array
{
    return [
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    ['text' => 'Let me check the weather for you.'],
                    [
                        'toolUse' => [
                            'toolUseId' => $toolCallId,
                            'name' => $toolName,
                            'input' => $input,
                        ],
                    ],
                ],
            ],
        ],
        'stopReason' => 'tool_use',
        'usage' => [
            'inputTokens' => 100,
            'outputTokens' => 50,
        ],
    ];
}

function fakeTextAfterToolResponse(string $text = 'The weather in Tokyo is sunny, 25°C.'): array
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
        'usage' => [
            'inputTokens' => 200,
            'outputTokens' => 30,
        ],
    ];
}

function fakeToolTextResponse(string $text = 'Hello!', int $inputTokens = 10, int $outputTokens = 5): array
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
        'usage' => [
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
        ],
    ];
}

class GetWeather implements Tool
{
    public function description(): string|Stringable
    {
        return 'Get current weather for a city.';
    }

    public function handle(Request $request): string|Stringable
    {
        return json_encode(['temperature' => 25, 'condition' => 'sunny', 'city' => $request['city']]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string('The city name'),
        ];
    }
}

function createGetWeatherTool(): Tool
{
    return new GetWeather;
}

describe('BedrockGateway tool use - generateText', function () {
    test('includes tool definitions in request body', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse()),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'What is the weather?')],
            tools: [$tool],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $tool = $body['toolConfig']['tools'][0]['toolSpec'] ?? null;

            return $tool !== null
                && $tool['name'] === 'GetWeather'
                && $tool['description'] === 'Get current weather for a city.'
                && isset($tool['inputSchema']['json'])
                && isset($body['toolConfig']['toolChoice']['auto']);
        });
    });

    test('executes tool calls and returns final response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence([
                Http::response(fakeToolUseResponse()),
                Http::response(fakeTextAfterToolResponse()),
            ]),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: 'You are a weather assistant.',
            messages: [new Message('user', 'What is the weather in Tokyo?')],
            tools: [$tool],
        );

        expect($response)->toBeInstanceOf(TextResponse::class)
            ->and($response->text)->toBe('The weather in Tokyo is sunny, 25°C.')
            ->and($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls->first())->toBeInstanceOf(ToolCall::class)
            ->and($response->toolCalls->first()->name)->toBe('GetWeather')
            ->and($response->toolResults)->toHaveCount(1)
            ->and($response->toolResults->first())->toBeInstanceOf(ToolResult::class);
    });

    test('sends tool results back to the API', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence([
                Http::response(fakeToolUseResponse()),
                Http::response(fakeTextAfterToolResponse()),
            ]),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'What is the weather in Tokyo?')],
            tools: [$tool],
        );

        $requests = Http::recorded();
        expect($requests)->toHaveCount(2);

        // Second request should contain tool results
        $secondBody = $requests[1][0]->data();
        $messages = $secondBody['messages'];

        // Should have: original user message, assistant toolUse, user toolResult
        $lastMessage = end($messages);
        expect($lastMessage['role'])->toBe('user')
            ->and($lastMessage['content'][0]['toolResult']['toolUseId'])->toBe('toolu_01');
    });

    test('tracks steps across tool call iterations', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence([
                Http::response(fakeToolUseResponse()),
                Http::response(fakeTextAfterToolResponse()),
            ]),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'What is the weather in Tokyo?')],
            tools: [$tool],
        );

        expect($response->steps)->toHaveCount(2);

        // Step 1: tool call
        $step1 = $response->steps->first();
        expect($step1->finishReason)->toBe(FinishReason::ToolCalls)
            ->and($step1->toolCalls)->toHaveCount(1)
            ->and($step1->toolResults)->toHaveCount(1);

        // Step 2: final text
        $step2 = $response->steps->last();
        expect($step2->finishReason)->toBe(FinishReason::Stop)
            ->and($step2->text)->toBe('The weather in Tokyo is sunny, 25°C.');
    });

    test('combines usage across steps', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence([
                Http::response(fakeToolUseResponse()),
                Http::response(fakeTextAfterToolResponse()),
            ]),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Weather?')],
            tools: [$tool],
        );

        // Usage should be combined: 100+200 input, 50+30 output
        expect($response->usage->promptTokens)->toBe(300)
            ->and($response->usage->completionTokens)->toBe(80);
    });

    test('invokes tool callbacks', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence([
                Http::response(fakeToolUseResponse()),
                Http::response(fakeTextAfterToolResponse()),
            ]),
        ]);

        $invokingCalled = false;
        $invokedCalled = false;

        $gateway = new BedrockGateway;
        $gateway->onToolInvocation(
            invoking: function ($tool, $arguments) use (&$invokingCalled) {
                $invokingCalled = true;
                expect($arguments)->toBe(['city' => 'Tokyo']);
            },
            invoked: function ($tool, $arguments, $result) use (&$invokedCalled) {
                $invokedCalled = true;
                expect($result)->toContain('sunny');
            },
        );

        $tool = createGetWeatherTool();

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Weather in Tokyo?')],
            tools: [$tool],
        );

        expect($invokingCalled)->toBeTrue()
            ->and($invokedCalled)->toBeTrue();
    });

    test('respects maxSteps limit', function () {
        // Always return tool_use to test that loop stops
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolUseResponse()),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $options = new TextGenerationOptions(maxSteps: 1);

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Weather?')],
            tools: [$tool],
            options: $options,
        );

        // Should stop after 1 step even though model wants more tool calls
        expect($response->steps)->toHaveCount(1);
        Http::assertSentCount(1);
    });

    test('handles response with no tool calls', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse()),
        ]);

        $gateway = new BedrockGateway;
        $tool = createGetWeatherTool();

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
            tools: [$tool],
        );

        expect($response->text)->toBe('Hello!')
            ->and($response->toolCalls)->toHaveCount(0)
            ->and($response->steps)->toHaveCount(1)
            ->and($response->steps->first()->finishReason)->toBe(FinishReason::Stop);
    });

    test('does not include tools in body when no tools given', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse('Hi')),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['toolConfig']);
        });
    });
});

describe('BedrockGateway tool use - Converse messages', function () {
    test('maps AssistantMessage with tool calls', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse('Done')),
        ]);

        $gateway = new BedrockGateway;

        $assistantMsg = new AssistantMessage(
            'I will check the weather.',
            collect([new ToolCall('toolu_01', 'GetWeather', ['city' => 'Tokyo'], 'toolu_01')]),
        );

        $toolResultMsg = new ToolResultMessage(
            collect([new ToolResult('toolu_01', 'GetWeather', ['city' => 'Tokyo'], '{"temp": 25}', 'toolu_01')]),
        );

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [
                new Message('user', 'What is the weather?'),
                $assistantMsg,
                $toolResultMsg,
                new Message('user', 'Thanks, what else?'),
            ],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $messages = $body['messages'];

            // Message 0: user
            $userMsg = $messages[0];
            expect($userMsg['role'])->toBe('user');

            // Message 1: assistant with toolUse
            $assistMsg = $messages[1];
            expect($assistMsg['role'])->toBe('assistant')
                ->and($assistMsg['content'])->toHaveCount(2)
                ->and($assistMsg['content'][0]['text'])->toBe('I will check the weather.')
                ->and($assistMsg['content'][1]['toolUse']['toolUseId'])->toBe('toolu_01')
                ->and($assistMsg['content'][1]['toolUse']['name'])->toBe('GetWeather');

            // Message 2: toolResult
            $toolMsg = $messages[2];
            expect($toolMsg['role'])->toBe('user')
                ->and($toolMsg['content'][0]['toolResult']['toolUseId'])->toBe('toolu_01');

            // Message 3: user follow-up
            expect($messages[3]['role'])->toBe('user');

            return true;
        });
    });
});

describe('BedrockGateway tool use - Converse tools', function () {
    test('maps tool with schema to Converse format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse('OK')),
        ]);

        $gateway = new BedrockGateway;

        $tool = new class implements Tool
        {
            public function description(): string
            {
                return 'Search for items.';
            }

            public function handle(Request $request): string
            {
                return 'results';
            }

            public function schema(JsonSchema $schema): array
            {
                return [
                    'query' => $schema->string('Search query'),
                    'limit' => $schema->integer('Max results'),
                ];
            }
        };

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Search')],
            tools: [$tool],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $toolDef = $body['toolConfig']['tools'][0]['toolSpec'] ?? null;

            return $toolDef !== null
                && str_ends_with($toolDef['name'], (string) class_basename($toolDef['name']))
                && $toolDef['description'] === 'Search for items.'
                && $toolDef['inputSchema']['json']['type'] === 'object'
                && isset($toolDef['inputSchema']['json']['properties']);
        });
    });

    test('maps tool without schema', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolTextResponse('OK')),
        ]);

        $gateway = new BedrockGateway;

        $tool = new class implements Tool
        {
            public function description(): string
            {
                return 'Get current time.';
            }

            public function handle(Request $request): string
            {
                return '2026-04-11T09:00:00Z';
            }

            public function schema(JsonSchema $schema): array
            {
                return [];
            }
        };

        $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'What time is it?')],
            tools: [$tool],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $toolDef = $body['toolConfig']['tools'][0]['toolSpec'] ?? null;

            return $toolDef !== null
                && $toolDef['inputSchema']['json']['type'] === 'object'
                && empty((array) $toolDef['inputSchema']['json']['properties']);
        });
    });
});

describe('BedrockGateway tool use - finish reason extraction', function () {
    test('maps stopReason tool_use to FinishReason::ToolCalls', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeToolUseResponse()),
        ]);

        $gateway = new BedrockGateway;

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Weather?')],
            // No tools provided, so it won't loop
            options: new TextGenerationOptions(maxSteps: 1),
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::ToolCalls);
    });

    test('maps stopReason end_turn to FinishReason::Stop', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeTextAfterToolResponse()),
        ]);

        $gateway = new BedrockGateway;

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Hello')],
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::Stop);
    });

    test('maps stopReason max_tokens to FinishReason::Length', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'output' => [
                    'message' => [
                        'role' => 'assistant',
                        'content' => [
                            ['text' => 'Truncated'],
                        ],
                    ],
                ],
                'stopReason' => 'max_tokens',
                'usage' => ['inputTokens' => 10, 'outputTokens' => 100],
            ]),
        ]);

        $gateway = new BedrockGateway;

        $response = $gateway->generateText(
            provider: makeToolTestProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new Message('user', 'Write a very long essay')],
        );

        expect($response->steps->first()->finishReason)->toBe(FinishReason::Length);
    });
});
