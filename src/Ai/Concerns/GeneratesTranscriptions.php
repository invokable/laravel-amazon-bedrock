<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TranscriptionResponse;

trait GeneratesTranscriptions
{
    /**
     * Generate a transcription from audio using the Bedrock Converse API with AudioBlock.
     *
     * Sends audio as an AudioBlock content block along with a transcription instruction
     * to a model that supports audio input (e.g., Amazon Nova 2 Lite).
     */
    public function generateTranscription(
        TranscriptionProvider $provider,
        string $model,
        TranscribableAudio $audio,
        ?string $language = null,
        bool $diarize = false,
        int $timeout = 30,
    ): TranscriptionResponse {
        $format = $this->audioMimeToFormat($audio->mimeType());

        $instruction = $this->buildTranscriptionInstruction($language, $diarize);

        $body = [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'audio' => [
                                'format' => $format,
                                'source' => [
                                    'bytes' => base64_encode($audio->content()),
                                ],
                            ],
                        ],
                        ['text' => $instruction],
                    ],
                ],
            ],
            'system' => [
                ['text' => 'You are an audio transcription assistant. Transcribe audio content accurately and faithfully. Output only the transcription text.'],
            ],
            'inferenceConfig' => [
                'maxTokens' => 4096,
            ],
        ];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $model, $timeout)
                ->post($this->converseUrl($model), $body),
        );

        $data = $response->json();

        return $this->parseTranscriptionResponse($data, $provider, $model);
    }

    /**
     * Build the transcription instruction text.
     */
    protected function buildTranscriptionInstruction(?string $language, bool $diarize): string
    {
        $instruction = 'Transcribe the audio content exactly as spoken.';

        if ($language !== null) {
            $instruction .= " The audio is in {$language}.";
        }

        if ($diarize) {
            $instruction .= ' Identify different speakers and label them (e.g., Speaker 1, Speaker 2).';
        }

        $instruction .= ' Output only the transcription text without any additional commentary.';

        return $instruction;
    }

    /**
     * Parse the Converse API response into a TranscriptionResponse.
     */
    protected function parseTranscriptionResponse(array $data, TranscriptionProvider $provider, string $model): TranscriptionResponse
    {
        $content = $data['output']['message']['content'] ?? [];
        $text = '';

        foreach ($content as $block) {
            if (isset($block['text'])) {
                $text .= $block['text'];
            }
        }

        $usage = $data['usage'] ?? [];

        return new TranscriptionResponse(
            $text,
            new Collection,
            new Usage(
                $usage['inputTokens'] ?? 0,
                $usage['outputTokens'] ?? 0,
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Map audio MIME type to Converse API AudioBlock format.
     */
    protected function audioMimeToFormat(?string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/flac', 'audio/x-flac' => 'flac',
            'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
            'audio/opus' => 'opus',
            'audio/aac', 'audio/x-aac' => 'aac',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/webm' => 'webm',
            'audio/x-matroska' => 'mka',
            'video/x-matroska' => 'mkv',
            default => 'mp3',
        };
    }
}
