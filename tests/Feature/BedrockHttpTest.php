<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Revolution\Amazon\Bedrock\Facades\Bedrock;

describe('Bedrock HTTP', function () {
    it('sends correct request to bedrock api', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_01XFDUDYJgAACzvnptvVoYEL',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'anthropic.claude-sonnet-4-20250514-v1:0',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Hello! How can I help you today?',
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 10,
                    'output_tokens' => 20,
                ],
            ]),
        ]);

        $response = Bedrock::text()
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Hello!')
            ->asText();

        expect($response->text)->toBe('Hello! How can I help you today?');
        expect($response->finishReason)->toBe('end_turn');
        expect($response->usage->promptTokens)->toBe(10);
        expect($response->usage->completionTokens)->toBe(20);
        expect($response->meta->id)->toBe('msg_01XFDUDYJgAACzvnptvVoYEL');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.us-east-1.amazonaws.com')
                && $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $request['anthropic_version'] === 'bedrock-2023-05-31'
                && $request['max_tokens'] === 2048
                && $request['system'][0]['type'] === 'text'
                && $request['system'][0]['text'] === 'You are a helpful assistant.'
                && $request['messages'][0]['role'] === 'user'
                && $request['messages'][0]['content']['text'] === 'Hello!';
        });
    });

    it('uses custom model when specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'anthropic.claude-3-haiku-20240307-v1:0',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->using(Bedrock::KEY, 'anthropic.claude-3-haiku-20240307-v1:0')
            ->withPrompt('Test')
            ->asText();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'anthropic.claude-3-haiku-20240307-v1:0');
        });
    });

    it('sends temperature when specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'test',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->withPrompt('Test')
            ->usingTemperature(0.7)
            ->asText();

        Http::assertSent(function ($request) {
            return $request['temperature'] === 0.7;
        });
    });

    it('sends custom max tokens when specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'test',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->withPrompt('Test')
            ->withMaxTokens(512)
            ->asText();

        Http::assertSent(function ($request) {
            return $request['max_tokens'] === 512;
        });
    });

    it('sends multiple system prompts', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'test',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->withSystemPrompts([
                'You are a helpful assistant.',
                'Always respond in Japanese.',
            ])
            ->withPrompt('Hello!')
            ->asText();

        Http::assertSent(function ($request) {
            return $request['system'][0]['type'] === 'text'
                && $request['system'][0]['text'] === 'You are a helpful assistant.'
                && $request['system'][1]['type'] === 'text'
                && $request['system'][1]['text'] === 'Always respond in Japanese.';
        });
    });

    it('does not send system when not specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'test',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->withPrompt('Hello!')
            ->asText();

        Http::assertSent(function ($request) {
            return ! isset($request['system']);
        });
    });

    it('does not send temperature when not specified', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response([
                'id' => 'msg_123',
                'model' => 'test',
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 10],
            ]),
        ]);

        Bedrock::text()
            ->withPrompt('Hello!')
            ->asText();

        Http::assertSent(function ($request) {
            return ! isset($request['temperature']);
        });
    });
});
