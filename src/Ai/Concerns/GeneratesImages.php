<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;

trait GeneratesImages
{
    /**
     * Generate an image using Amazon Nova Canvas.
     *
     * @param  array<ImageFile>  $attachments
     * @param  '3:2'|'2:3'|'1:1'  $size
     * @param  'low'|'medium'|'high'  $quality
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        $options = $provider->defaultImageOptions($size, $quality);

        $body = [
            'taskType' => 'TEXT_IMAGE',
            'textToImageParams' => [
                'text' => $prompt,
            ],
            'imageGenerationConfig' => [
                'width' => $options['width'],
                'height' => $options['height'],
                'quality' => $options['quality'],
                'numberOfImages' => 1,
            ],
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout ?? 120)
                ->post($this->invokeUrl($model), $body),
        )->json();

        $images = new Collection(
            array_map(
                fn (string $base64) => new GeneratedImage($base64, 'image/png'),
                $response['images'] ?? [],
            ),
        );

        return new ImageResponse(
            $images,
            new Usage,
            new Meta($provider->name(), $model),
        );
    }
}
