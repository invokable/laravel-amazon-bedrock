<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Laravel\Ai\Responses\TranscriptionResponse;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

function fakeTranscribableAudio(string $content = 'fake-audio-data', ?string $mimeType = 'audio/mpeg'): TranscribableAudio
{
    return new class($content, $mimeType) implements TranscribableAudio
    {
        public function __construct(
            private readonly string $audioContent,
            private readonly ?string $audioMimeType,
        ) {}

        public function content(): string
        {
            return $this->audioContent;
        }

        public function mimeType(): ?string
        {
            return $this->audioMimeType;
        }

        public function transcription(): PendingTranscriptionGeneration
        {
            return new PendingTranscriptionGeneration($this);
        }

        public function __toString(): string
        {
            return $this->audioContent;
        }
    };
}

function fakeConverseTranscriptionResponse(string $text = 'Hello, world!', int $inputTokens = 100, int $outputTokens = 20): array
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
        'usage' => [
            'inputTokens' => $inputTokens,
            'outputTokens' => $outputTokens,
        ],
        'stopReason' => 'end_turn',
    ];
}

describe('BedrockGateway generateTranscription', function () {
    test('returns a TranscriptionResponse instance', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect($response)->toBeInstanceOf(TranscriptionResponse::class);
    });

    test('returns transcribed text', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseTranscriptionResponse('This is the transcribed text from the audio.'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect($response->text)->toBe('This is the transcribed text from the audio.');
    });

    test('returns empty segments collection', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect($response->segments)->toBeEmpty();
    });

    test('returns usage from response', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseTranscriptionResponse(inputTokens: 150, outputTokens: 30),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect($response->usage->promptTokens)->toBe(150);
        expect($response->usage->completionTokens)->toBe(30);
    });

    test('sends request to converse URL', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-east-1.amazonaws.com/model/us.amazon.nova-2-lite-v1:0/converse',
            );
        });
    });

    test('sends audio block with base64-encoded content', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $audioContent = 'fake-audio-binary-data';
        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio($audioContent, 'audio/mpeg'),
        );

        Http::assertSent(function ($request) use ($audioContent) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];

            $audioBlock = $content[0] ?? [];

            return ($audioBlock['audio']['format'] ?? '') === 'mp3'
                && ($audioBlock['audio']['source']['bytes'] ?? '') === base64_encode($audioContent);
        });
    });

    test('sends transcription instruction in text block', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];
            $textBlock = $content[1] ?? [];

            return str_contains($textBlock['text'] ?? '', 'Transcribe the audio content');
        });
    });

    test('includes language in instruction when provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
            language: 'Japanese',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];
            $textBlock = $content[1] ?? [];

            return str_contains($textBlock['text'] ?? '', 'Japanese');
        });
    });

    test('includes diarization instruction when enabled', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
            diarize: true,
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];
            $textBlock = $content[1] ?? [];

            return str_contains($textBlock['text'] ?? '', 'Identify different speakers');
        });
    });

    test('maps wav mime type to correct format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio('data', 'audio/wav'),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $audioBlock = $body['messages'][0]['content'][0] ?? [];

            return ($audioBlock['audio']['format'] ?? '') === 'wav';
        });
    });

    test('maps flac mime type to correct format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio('data', 'audio/flac'),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $audioBlock = $body['messages'][0]['content'][0] ?? [];

            return ($audioBlock['audio']['format'] ?? '') === 'flac';
        });
    });

    test('maps webm mime type to correct format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio('data', 'audio/webm'),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $audioBlock = $body['messages'][0]['content'][0] ?? [];

            return ($audioBlock['audio']['format'] ?? '') === 'webm';
        });
    });

    test('defaults unknown mime type to mp3 format', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio('data', 'audio/unknown-format'),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $audioBlock = $body['messages'][0]['content'][0] ?? [];

            return ($audioBlock['audio']['format'] ?? '') === 'mp3';
        });
    });

    test('uses configured region in URL', function () {
        Http::fake([
            'bedrock-runtime.eu-west-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(['region' => 'eu-west-1']),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.eu-west-1.amazonaws.com');
        });
    });

    test('meta contains provider name and model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('us.amazon.nova-2-lite-v1:0');
    });

    test('can be cast to string', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeConverseTranscriptionResponse('Hello from the audio.'),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        expect((string) $response)->toBe('Hello from the audio.');
    });

    test('sends system prompt for transcription context', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeConverseTranscriptionResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateTranscription(
            provider: makeProvider(),
            model: 'us.amazon.nova-2-lite-v1:0',
            audio: fakeTranscribableAudio(),
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);
            $system = $body['system'] ?? [];

            return isset($system[0]['text'])
                && str_contains($system[0]['text'], 'transcription');
        });
    });
});

describe('BedrockProvider transcription defaults', function () {
    test('returns default transcription model', function () {
        $provider = makeProvider();
        expect($provider->defaultTranscriptionModel())->toBe('us.amazon.nova-2-lite-v1:0');
    });

    test('uses configured transcription model', function () {
        $provider = makeProvider([
            'models' => ['transcription' => ['default' => 'us.amazon.nova-2-pro-v1:0']],
        ]);
        expect($provider->defaultTranscriptionModel())->toBe('us.amazon.nova-2-pro-v1:0');
    });

    test('returns BedrockGateway as transcription gateway', function () {
        $provider = makeProvider();
        expect($provider->transcriptionGateway())->toBeInstanceOf(BedrockGateway::class);
    });
});
