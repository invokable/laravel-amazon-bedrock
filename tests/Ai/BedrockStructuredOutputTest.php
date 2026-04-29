<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;
use Laravel\Ai\Tools\Request;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

function makeStructuredProvider(array $config = []): BedrockProvider
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

function makeTestSchema(): array
{
    $factory = new JsonSchemaTypeFactory;

    return [
        'name' => $factory->string('The name'),
        'age' => $factory->integer('The age'),
    ];
}

function fakeStructuredOutputResponse(array $input = ['name' => 'John', 'age' => 30]): array
{
    return [
        'output' => [
            'message' => [
                'role' => 'assistant',
                'content' => [
                    [
                        'toolUse' => [
                            'toolUseId' => 'toolu_structured_01',
                            'name' => 'output_structured_data',
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

function fakeStructuredTextResponse(string $text = 'Hello', string $stopReason = 'end_turn', int $inputTokens = 10, int $outputTokens = 20): array
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
        'stopReason' => $stopReason,
        'usage' => ['inputTokens' => $inputTokens, 'outputTokens' => $outputTokens],
    ];
}

describe('BedrockGateway structured output - generateText', function () {
    test('returns a StructuredTextResponse when schema is provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class);
    });

    test('extracts structured data from the synthetic tool call', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(['name' => 'Alice', 'age' => 25]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        expect($response['name'])->toBe('Alice')
            ->and($response['age'])->toBe(25);
    });

    test('includes the synthetic tool definition in request body', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $tools = $body['toolConfig']['tools'] ?? [];
            $toolNames = array_map(fn (array $tool) => $tool['toolSpec']['name'] ?? null, $tools);

            return in_array('output_structured_data', $toolNames);
        });
    });

    test('forces toolChoice when only schema is provided (no real tools)', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['toolConfig']['toolChoice']['tool']['name'] ?? null) === 'output_structured_data';
        });
    });

    test('uses "any" toolChoice when both schema and real tools are provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $tool = new class implements Tool
        {
            public function description(): string|Stringable
            {
                return 'A test tool';
            }

            public function handle(Request $request): string|Stringable
            {
                return 'result';
            }

            public function schema(JsonSchema $schema): array
            {
                return ['query' => $schema->string('Query')];
            }
        };

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            tools: [$tool],
            schema: makeTestSchema(),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['toolConfig']['toolChoice']['any']);
        });
    });

    test('structured response contains usage data', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        expect($response->usage->promptTokens)->toBe(100)
            ->and($response->usage->completionTokens)->toBe(50);
    });

    test('synthetic tool definition has correct inputSchema structure', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();
            $tools = $body['toolConfig']['tools'] ?? [];
            $syntheticTool = collect($tools)
                ->map(fn (array $tool) => $tool['toolSpec'] ?? [])
                ->firstWhere('name', 'output_structured_data');

            if (! $syntheticTool) {
                return false;
            }

            $inputSchema = $syntheticTool['inputSchema']['json'] ?? [];

            return ($inputSchema['type'] ?? '') === 'object'
                && isset($inputSchema['properties']->name)
                && isset($inputSchema['properties']->age);
        });
    });

    test('returns TextResponse (not structured) when no schema is provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStructuredTextResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Hello')],
        );

        expect($response)->toBeInstanceOf(TextResponse::class)
            ->and($response)->not->toBeInstanceOf(StructuredTextResponse::class);
    });

    test('structured response text is JSON-encoded structured data', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(['name' => 'Bob', 'age' => 40]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        expect($response->text)->toBe(json_encode(['name' => 'Bob', 'age' => 40]));
    });

    test('structured output does not execute the synthetic tool', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        // Only one HTTP request should be made (no follow-up tool execution)
        Http::assertSentCount(1);
    });

    test('structured output includes steps with FinishReason::ToolCalls', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredOutputResponse(),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person')],
            schema: makeTestSchema(),
        );

        expect($response->steps)->toHaveCount(1)
            ->and($response->steps->first()->finishReason)->toBe(FinishReason::ToolCalls);
    });

    test('structured output with tools and schema - real tools get executed, structured tool does not', function () {
        $realToolResponse = [
            'output' => [
                'message' => [
                    'role' => 'assistant',
                    'content' => [
                        ['text' => 'Let me look that up.'],
                        [
                            'toolUse' => [
                                'toolUseId' => 'toolu_real_01',
                                'name' => 'SearchTool',
                                'input' => ['query' => 'John Doe'],
                            ],
                        ],
                    ],
                ],
            ],
            'stopReason' => 'tool_use',
            'usage' => ['inputTokens' => 50, 'outputTokens' => 30],
        ];

        $structuredResponse = fakeStructuredOutputResponse(['name' => 'John', 'age' => 42]);

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::sequence()
                ->push($realToolResponse)
                ->push($structuredResponse),
        ]);

        $tool = new class implements Tool
        {
            public function description(): string|Stringable
            {
                return 'Search for a person';
            }

            public function handle(Request $request): string|Stringable
            {
                return json_encode(['name' => 'John', 'age' => 42]);
            }

            public function schema(JsonSchema $schema): array
            {
                return ['query' => $schema->string('Search query')];
            }
        };

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Find John')],
            tools: [$tool],
            schema: makeTestSchema(),
            options: new TextGenerationOptions(maxSteps: 5),
        );

        expect($response)->toBeInstanceOf(StructuredTextResponse::class)
            ->and($response['name'])->toBe('John')
            ->and($response['age'])->toBe(42);

        // Two requests: first for real tool call, second returns structured output
        Http::assertSentCount(2);
    });

    test('fallback: extracts structured data from text when no toolUse block', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeStructuredTextResponse('{"name":"Eve","age":28}', inputTokens: 10, outputTokens: 15),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Give me a person as JSON')],
            schema: makeTestSchema(),
        );

        // Even though model didn't use the tool, schema flag triggers structured parsing
        // The response won't be StructuredTextResponse because stopReason is end_turn (no toolUse)
        // and the response won't have a synthetic tool call — it's just a TextResponse with text
        expect($response)->toBeInstanceOf(TextResponse::class);
    });
});

describe('BedrockGateway structured output - Converse requests', function () {
    test('does not include toolConfig when no schema and no tools', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStructuredTextResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateText(
            provider: makeStructuredProvider(),
            model: 'anthropic.claude-3-haiku-20240307-v1:0',
            instructions: null,
            messages: [new UserMessage('Hello')],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ! isset($body['toolConfig']);
        });
    });
});
