<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\ImageResponse;
use Revolution\Amazon\Bedrock\Ai\BedrockGateway;

function fakeNovaCanvasResponse(array $images = []): array
{
    return [
        'images' => $images ?: [base64_encode('fake-png-data')],
    ];
}

describe('BedrockGateway generateImage', function () {
    test('returns an ImageResponse instance', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cute cat',
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);
    });

    test('returns generated image with base64 data', function () {
        $base64 = base64_encode('test-image-data');

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse([$base64])),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cute cat',
        );

        expect($response->images)->toHaveCount(1);
        expect($response->firstImage())->toBeInstanceOf(GeneratedImage::class);
        expect($response->firstImage()->image)->toBe($base64);
        expect($response->firstImage()->mime)->toBe('image/png');
    });

    test('sends correct TEXT_IMAGE request body', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A beautiful sunset',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['taskType'] === 'TEXT_IMAGE'
                && $body['textToImageParams']['text'] === 'A beautiful sunset'
                && $body['imageGenerationConfig']['numberOfImages'] === 1
                && $body['imageGenerationConfig']['width'] === 1024
                && $body['imageGenerationConfig']['height'] === 1024
                && $body['imageGenerationConfig']['quality'] === 'standard';
        });
    });

    test('sends request to correct Bedrock URL', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-east-1.amazonaws.com/model/amazon.nova-canvas-v1:0/invoke',
            );
        });
    });

    test('uses configured region in URL', function () {
        Http::fake([
            'bedrock-runtime.eu-west-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(['region' => 'eu-west-1']),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bedrock-runtime.eu-west-1.amazonaws.com');
        });
    });

    test('maps 1:1 size to 1024x1024', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
            size: '1:1',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['imageGenerationConfig']['width'] === 1024
                && $body['imageGenerationConfig']['height'] === 1024;
        });
    });

    test('maps 3:2 size to 1536x1024', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A landscape',
            size: '3:2',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['imageGenerationConfig']['width'] === 1536
                && $body['imageGenerationConfig']['height'] === 1024;
        });
    });

    test('maps 2:3 size to 1024x1536', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A portrait',
            size: '2:3',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['imageGenerationConfig']['width'] === 1024
                && $body['imageGenerationConfig']['height'] === 1536;
        });
    });

    test('maps high quality to premium', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A masterpiece',
            quality: 'high',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['imageGenerationConfig']['quality'] === 'premium';
        });
    });

    test('maps low quality to standard', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A quick sketch',
            quality: 'low',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['imageGenerationConfig']['quality'] === 'standard';
        });
    });

    test('meta contains provider name and model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('amazon.nova-canvas-v1:0');
    });

    test('handles multiple images in response', function () {
        $base64a = base64_encode('image-a');
        $base64b = base64_encode('image-b');

        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(
                fakeNovaCanvasResponse([$base64a, $base64b]),
            ),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'Two cats',
        );

        expect($response->images)->toHaveCount(2);
        expect($response->images[0]->image)->toBe($base64a);
        expect($response->images[1]->image)->toBe($base64b);
    });

    test('uses 120 second default timeout', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeNovaCanvasResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'amazon.nova-canvas-v1:0',
            prompt: 'A cat',
        );

        Http::assertSent(function ($request) {
            return true;
        });
    });
});

describe('BedrockProvider image defaults', function () {
    test('returns default image model', function () {
        $provider = makeProvider();
        expect($provider->defaultImageModel())->toBe('amazon.nova-canvas-v1:0');
    });

    test('uses configured image model', function () {
        $provider = makeProvider([
            'models' => ['image' => ['default' => 'stability.sd3-5-large-v1:0']],
        ]);
        expect($provider->defaultImageModel())->toBe('stability.sd3-5-large-v1:0');
    });

    test('returns default image options with 1:1 size', function () {
        $provider = makeProvider();
        $options = $provider->defaultImageOptions('1:1', null);

        expect($options['width'])->toBe(1024);
        expect($options['height'])->toBe(1024);
        expect($options['quality'])->toBe('standard');
    });

    test('returns default image options with no size', function () {
        $provider = makeProvider();
        $options = $provider->defaultImageOptions(null, null);

        expect($options['width'])->toBe(1024);
        expect($options['height'])->toBe(1024);
    });

    test('maps high quality to premium', function () {
        $provider = makeProvider();
        $options = $provider->defaultImageOptions(null, 'high');

        expect($options['quality'])->toBe('premium');
    });

    test('maps medium quality to standard', function () {
        $provider = makeProvider();
        $options = $provider->defaultImageOptions(null, 'medium');

        expect($options['quality'])->toBe('standard');
    });
});
