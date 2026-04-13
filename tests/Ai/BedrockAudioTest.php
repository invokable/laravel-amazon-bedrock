<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\AudioResponse;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

function makeAudioProvider(array $config = []): BedrockProvider
{
    return new BedrockProvider(
        config: array_merge([
            'name' => 'bedrock',
            'driver' => 'bedrock',
            'key' => 'test-access-key',
            'secret' => 'test-secret-key',
            'region' => 'us-east-1',
        ], $config),
        events: app(Dispatcher::class),
    );
}

describe('BedrockGateway generateAudio', function () {
    test('returns an AudioResponse instance', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio-bytes'),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'Ruth',
        );

        expect($response)->toBeInstanceOf(AudioResponse::class);
    });

    test('returns base64-encoded audio content', function () {
        $audioBytes = 'fake-mp3-audio-data';

        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response($audioBytes),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'Ruth',
        );

        expect($response->audio)->toBe(base64_encode($audioBytes));
        expect($response->content())->toBe($audioBytes);
    });

    test('returns audio/mpeg mime type', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'Ruth',
        );

        expect($response->mimeType())->toBe('audio/mpeg');
    });

    test('maps default-female to Ruth', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'default-female',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['VoiceId'] === 'Ruth';
        });
    });

    test('maps default-male to Matthew', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello world',
            voice: 'default-male',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['VoiceId'] === 'Matthew';
        });
    });

    test('passes custom voice directly', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'neural',
            text: 'Hello world',
            voice: 'Joanna',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['VoiceId'] === 'Joanna';
        });
    });

    test('sends correct request body to Polly', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'I love coding with Laravel.',
            voice: 'Ruth',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['Engine'] === 'generative'
                && $body['OutputFormat'] === 'mp3'
                && $body['Text'] === 'I love coding with Laravel.'
                && $body['VoiceId'] === 'Ruth'
                && $body['TextType'] === 'text';
        });
    });

    test('sends request to Polly endpoint', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello',
            voice: 'Ruth',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'polly.us-east-1.amazonaws.com/v1/speech');
        });
    });

    test('uses configured region in Polly URL', function () {
        Http::fake([
            'polly.eu-west-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(['region' => 'eu-west-1']),
            model: 'generative',
            text: 'Hello',
            voice: 'Ruth',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'polly.eu-west-1.amazonaws.com');
        });
    });

    test('meta contains provider name and model', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello',
            voice: 'Ruth',
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('generative');
    });

    test('uses neural engine when specified', function () {
        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response('fake-audio'),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'neural',
            text: 'Hello',
            voice: 'Joanna',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['Engine'] === 'neural';
        });
    });

    test('audio can be converted to string', function () {
        $audioBytes = 'test-audio-content';

        Http::fake([
            'polly.us-east-1.amazonaws.com/*' => Http::response($audioBytes),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateAudio(
            provider: makeAudioProvider(),
            model: 'generative',
            text: 'Hello',
            voice: 'Ruth',
        );

        expect((string) $response)->toBe($audioBytes);
    });
});

describe('BedrockProvider audio defaults', function () {
    test('returns default audio model as generative', function () {
        $provider = makeAudioProvider();
        expect($provider->defaultAudioModel())->toBe('generative');
    });

    test('uses configured audio model', function () {
        $provider = makeAudioProvider([
            'models' => ['audio' => ['default' => 'neural']],
        ]);
        expect($provider->defaultAudioModel())->toBe('neural');
    });
});
