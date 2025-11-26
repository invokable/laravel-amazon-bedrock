<?php

declare(strict_types=1);

use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\Testing\TextResponseFake;
use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

describe('Bedrock Fake', function () {
    it('can fake bedrock responses', function () {
        $fake = Bedrock::fake([
            TextResponseFake::make()->withText('Hello! How can I help you?'),
        ]);

        $response = Bedrock::text()
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Hello!')
            ->asText();

        expect($response->text)->toBe('Hello! How can I help you?');
        expect($response->finishReason)->toBe('end_turn');
    });

    it('can assert prompt was sent', function () {
        $fake = Bedrock::fake([
            TextResponseFake::make()->withText('Test response'),
        ]);

        Bedrock::text()
            ->withPrompt('Test prompt')
            ->asText();

        $fake->assertPrompt('Test prompt');
    });

    it('can assert system prompt was sent', function () {
        $fake = Bedrock::fake([
            TextResponseFake::make()->withText('Test response'),
        ]);

        Bedrock::text()
            ->withSystemPrompt('You are a helpful assistant.')
            ->withPrompt('Hello!')
            ->asText();

        $fake->assertSystemPrompt('You are a helpful assistant.');
    });

    it('can assert call count', function () {
        $fake = Bedrock::fake([
            TextResponseFake::make()->withText('Response 1'),
            TextResponseFake::make()->withText('Response 2'),
        ]);

        Bedrock::text()->withPrompt('First')->asText();
        Bedrock::text()->withPrompt('Second')->asText();

        $fake->assertCallCount(2);
    });

    it('can assert request details', function () {
        $fake = Bedrock::fake([
            TextResponseFake::make()->withText('Test'),
        ]);

        Bedrock::text()
            ->using('bedrock', 'anthropic.claude-3-haiku-20240307-v1:0')
            ->withSystemPrompt('System 1')
            ->withSystemPrompt('System 2')
            ->withPrompt('User prompt')
            ->withMaxTokens(1024)
            ->usingTemperature(0.7)
            ->asText();

        $fake->assertRequest(function (array $requests) {
            expect($requests)->toHaveCount(1);
            expect($requests[0]['model'])->toBe('anthropic.claude-3-haiku-20240307-v1:0');
            expect($requests[0]['systemPrompts'])->toBe(['System 1', 'System 2']);
            expect($requests[0]['prompt'])->toBe('User prompt');
            expect($requests[0]['maxTokens'])->toBe(1024);
            expect($requests[0]['temperature'])->toBe(0.7);
        });
    });

    it('can fake with custom usage and meta', function () {
        Bedrock::fake([
            TextResponseFake::make()
                ->withText('Response with usage')
                ->withUsage(new Usage(100, 50))
                ->withMeta(new Meta('msg_123', 'claude-3-sonnet')),
        ]);

        $response = Bedrock::text()
            ->withPrompt('Test')
            ->asText();

        expect($response->usage->promptTokens)->toBe(100);
        expect($response->usage->completionTokens)->toBe(50);
        expect($response->meta->id)->toBe('msg_123');
        expect($response->meta->model)->toBe('claude-3-sonnet');
    });

    it('returns default response when no responses provided', function () {
        Bedrock::fake();

        $response = Bedrock::text()
            ->withPrompt('Test')
            ->asText();

        expect($response->text)->toBe('');
        expect($response->finishReason)->toBe('end_turn');
    });
});
