<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\EmbeddingGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Gateway\RerankingGateway;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Gateway\TranscriptionGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\RerankingProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Providers\Concerns\GeneratesAudio;
use Laravel\Ai\Providers\Concerns\GeneratesEmbeddings;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\GeneratesTranscriptions;
use Laravel\Ai\Providers\Concerns\HasAudioGateway;
use Laravel\Ai\Providers\Concerns\HasEmbeddingGateway;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Concerns\HasRerankingGateway;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\HasTranscriptionGateway;
use Laravel\Ai\Providers\Concerns\Reranks;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

class BedrockProvider extends Provider implements AudioProvider, EmbeddingProvider, ImageProvider, RerankingProvider, TextProvider, TranscriptionProvider
{
    use GeneratesAudio;
    use GeneratesEmbeddings;
    use GeneratesImages;
    use GeneratesText;
    use GeneratesTranscriptions;
    use HasAudioGateway;
    use HasEmbeddingGateway;
    use HasImageGateway;
    use HasRerankingGateway;
    use HasTextGateway;
    use HasTranscriptionGateway;
    use Reranks;
    use StreamsText;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new BedrockGateway;
    }

    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new BedrockGateway;
    }

    public function embeddingGateway(): EmbeddingGateway
    {
        return $this->embeddingGateway ??= new BedrockGateway;
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'global.anthropic.claude-sonnet-4-6';
    }

    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'generative';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'global.anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'global.anthropic.claude-opus-4-7';
    }

    public function defaultEmbeddingsModel(): string
    {
        return $this->config['models']['embeddings']['default'] ?? 'amazon.titan-embed-text-v2:0';
    }

    public function defaultEmbeddingsDimensions(): int
    {
        return (int) ($this->config['models']['embeddings']['dimensions'] ?? 1024);
    }

    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new BedrockGateway;
    }

    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'stability.stable-image-core-v1:1';
    }

    public function rerankingGateway(): RerankingGateway
    {
        return $this->rerankingGateway ??= new BedrockGateway;
    }

    public function defaultRerankingModel(): string
    {
        return $this->config['models']['reranking']['default'] ?? 'cohere.rerank-v3-5:0';
    }

    public function transcriptionGateway(): TranscriptionGateway
    {
        return $this->transcriptionGateway ??= new BedrockGateway;
    }

    public function defaultTranscriptionModel(): string
    {
        return $this->config['models']['transcription']['default'] ?? 'us.amazon.nova-2-lite-v1:0';
    }

    /**
     * Map AI SDK size/quality to Nova Canvas width/height/quality.
     *
     * @param  '3:2'|'2:3'|'1:1'|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     */
    public function defaultImageOptions(?string $size = null, $quality = null): array
    {
        [$width, $height] = match ($size) {
            '1:1' => [1024, 1024],
            '3:2' => [1536, 1024],
            '2:3' => [1024, 1536],
            default => [1024, 1024],
        };

        return [
            'width' => $width,
            'height' => $height,
            'quality' => match ($quality) {
                'low' => 'standard',
                'medium' => 'standard',
                'high' => 'premium',
                default => 'standard',
            },
        ];
    }
}
