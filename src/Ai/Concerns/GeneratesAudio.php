<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;

trait GeneratesAudio
{
    /**
     * Generate audio from the given text using Amazon Polly.
     *
     * The $model parameter maps to the Polly Engine (generative, neural, long-form, standard).
     * The $voice parameter maps to the Polly VoiceId (e.g., Ruth, Matthew, Joanna).
     *
     * @see https://docs.aws.amazon.com/polly/latest/dg/API_SynthesizeSpeech.html
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 30,
    ): AudioResponse {
        $voice = match ($voice) {
            'default-male' => 'Matthew',
            'default-female' => 'Ruth',
            default => $voice,
        };

        $body = array_filter([
            'Engine' => $model,
            'OutputFormat' => 'mp3',
            'Text' => $text,
            'VoiceId' => $voice,
            'TextType' => 'text',
        ]);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->pollyClient($provider, $timeout)
                ->post('v1/speech', $body),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            'audio/mpeg',
        );
    }
}
