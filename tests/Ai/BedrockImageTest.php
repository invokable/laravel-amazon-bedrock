<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Laravel\Ai\Files\Image;
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

function fakeStabilityResponse(array $images = []): array
{
    return [
        'seeds' => [123456789],
        'finish_reasons' => [null],
        'images' => $images ?: [base64_encode('fake-stability-png-data')],
    ];
}

describe('BedrockGateway generateImage (Stability AI)', function () {
    test('returns an ImageResponse for Stability AI model', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-core-v1:1',
            prompt: 'A cute cat',
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);
    });

    test('sends simple prompt body for Stability AI model', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-core-v1:1',
            prompt: 'A beautiful sunset',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['prompt'])
                && $body['prompt'] === 'A beautiful sunset'
                && ! isset($body['taskType'])
                && ! isset($body['textToImageParams'])
                && ! isset($body['imageGenerationConfig']);
        });
    });

    test('sends request to correct URL for Stability AI model', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-core-v1:1',
            prompt: 'A cat',
        );

        Http::assertSent(function ($request) {
            return str_contains(
                $request->url(),
                'bedrock-runtime.us-west-2.amazonaws.com/model/stability.stable-image-core-v1:1/invoke',
            );
        });
    });

    test('returns base64 image from Stability AI response', function () {
        $base64 = base64_encode('stability-image-data');

        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse([$base64])),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-core-v1:1',
            prompt: 'A cat',
        );

        expect($response->images)->toHaveCount(1);
        expect($response->firstImage())->toBeInstanceOf(GeneratedImage::class);
        expect($response->firstImage()->image)->toBe($base64);
        expect($response->firstImage()->mime)->toBe('image/png');
    });

    test('meta contains provider and model for Stability AI', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.sd3-5-large-v1:0',
            prompt: 'A cat',
        );

        expect($response->meta->provider)->toBe('bedrock');
        expect($response->meta->model)->toBe('stability.sd3-5-large-v1:0');
    });

    test('works with Stable Image Ultra model', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-ultra-v1:1',
            prompt: 'A landscape',
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'stability.stable-image-ultra-v1:1');
        });
    });

    test('works with SD3.5 Large model', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.sd3-5-large-v1:0',
            prompt: 'A portrait',
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);
    });
});

describe('BedrockGateway generateImage (Stability AI image editing)', function () {
    test('sends image field when Base64Image attachment provided (inpaint)', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $attachment = Image::fromBase64(base64_encode('fake-input-image'));

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-image-inpaint-v1:0',
            prompt: 'Replace the background with mountains',
            attachments: [$attachment],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return isset($body['image'])
                && $body['image'] === base64_encode('fake-input-image')
                && $body['prompt'] === 'Replace the background with mountains'
                && ! isset($body['aspect_ratio']);
        });
    });

    test('sends image field when Base64Image attachment provided (erase)', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $base64Content = base64_encode('my-image-bytes');
        $attachment = Image::fromBase64($base64Content);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-image-erase-object-v1:0',
            prompt: 'Remove the car',
            attachments: [$attachment],
        );

        Http::assertSent(function ($request) use ($base64Content) {
            $body = json_decode($request->body(), true);

            return $body['image'] === $base64Content
                && ! isset($body['aspect_ratio']);
        });
    });

    test('sends image field for remove-background model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $attachment = Image::fromBase64(base64_encode('photo-data'));

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-image-remove-background-v1:0',
            prompt: '',
            attachments: [$attachment],
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);
        Http::assertSent(fn ($request) => isset(json_decode($request->body(), true)['image']));
    });

    test('sends image field for style transfer model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $attachment = Image::fromBase64(base64_encode('source-image'));

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-style-transfer-v1:0',
            prompt: 'Oil painting style',
            attachments: [$attachment],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['prompt'] === 'Oil painting style'
                && isset($body['image'])
                && ! isset($body['aspect_ratio']);
        });
    });

    test('uses only first attachment when multiple are provided', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $first = Image::fromBase64(base64_encode('first-image'));
        $second = Image::fromBase64(base64_encode('second-image'));

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-image-inpaint-v1:0',
            prompt: 'Edit image',
            attachments: [$first, $second],
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['image'] === base64_encode('first-image');
        });
    });

    test('uses aspect_ratio when no attachments (text-to-image remains unchanged)', function () {
        Http::fake([
            'bedrock-runtime.us-west-2.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $gateway = new BedrockGateway;
        $gateway->generateImage(
            provider: makeProvider(['region' => 'us-west-2']),
            model: 'stability.stable-image-core-v1:1',
            prompt: 'A sunset',
            attachments: [],
            size: '3:2',
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['aspect_ratio'] === '3:2'
                && ! isset($body['image']);
        });
    });

    test('returns ImageResponse for editing model', function () {
        Http::fake([
            'bedrock-runtime.us-east-1.amazonaws.com/*' => Http::response(fakeStabilityResponse()),
        ]);

        $attachment = Image::fromBase64(base64_encode('input-image'));

        $gateway = new BedrockGateway;
        $response = $gateway->generateImage(
            provider: makeProvider(),
            model: 'stability.stable-image-search-replace-v1:0',
            prompt: 'Replace the dog with a cat',
            attachments: [$attachment],
        );

        expect($response)->toBeInstanceOf(ImageResponse::class);
        expect($response->images)->toHaveCount(1);
        expect($response->firstImage())->toBeInstanceOf(GeneratedImage::class);
    });
});

describe('BedrockProvider image defaults', function () {
    test('returns default image model', function () {
        $provider = makeProvider();
        expect($provider->defaultImageModel())->toBe('stability.stable-image-core-v1:1');
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
