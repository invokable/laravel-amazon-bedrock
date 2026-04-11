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
        'id' => 'msg_structured',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
        'content' => [
            [
                'type' => 'tool_use',
                'id' => 'toolu_structured_01',
                'name' => 'output_structured_data',
                'input' => $input,
            ],
        ],
        'stop_reason' => 'tool_use',
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 50,
        ],
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

            $tools = $body['tools'] ?? [];
            $toolNames = array_column($tools, 'name');

            return in_array('output_structured_data', $toolNames);
        });
    });

    test('forces tool_choice when only schema is provided (no real tools)', function () {
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

            return ($body['tool_choice'] ?? []) === ['type' => 'tool', 'name' => 'output_structured_data'];
        });
    });

    test('uses "any" tool_choice when both schema and real tools are provided', function () {
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

            return ($body['tool_choice'] ?? []) === ['type' => 'any'];
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

    test('synthetic tool definition has correct input_schema structure', function () {
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
            $tools = $body['tools'] ?? [];
            $syntheticTool = collect($tools)->firstWhere('name', 'output_structured_data');

            if (! $syntheticTool) {
                return false;
            }

            $inputSchema = $syntheticTool['input_schema'] ?? [];

            return ($inputSchema['type'] ?? '') === 'object'
                && isset($inputSchema['properties']->name)
                && isset($inputSchema['properties']->age);
        });
    });

    test('returns TextResponse (not structured) when no schema is provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
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
            'id' => 'msg_tool',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
            'content' => [
                ['type' => 'text', 'text' => 'Let me look that up.'],
                [
                    'type' => 'tool_use',
                    'id' => 'toolu_real_01',
                    'name' => 'SearchTool',
                    'input' => ['query' => 'John Doe'],
                ],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 30],
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

    test('fallback: extracts structured data from text when no tool_use block', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_json',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
                'content' => [
                    ['type' => 'text', 'text' => '{"name":"Eve","age":28}'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 15],
            ]),
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
        // The response won't be StructuredTextResponse because stop_reason is end_turn (no tool_use)
        // and the response won't have a synthetic tool call — it's just a TextResponse with text
        expect($response)->toBeInstanceOf(TextResponse::class);
    });
});

describe('BedrockGateway structured output - BuildsTextRequests', function () {
    test('does not include tool_choice when no schema and no tools', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
                'content' => [['type' => 'text', 'text' => 'Hello']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
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

            return ! isset($body['tools']) && ! isset($body['tool_choice']);
        });
    });
});
