<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Testing;

class StreamResponseFake
{
    /**
     * @param  array<int, string>  $chunks
     */
    protected function __construct(
        protected string $text,
        protected array $chunks = [],
    ) {}

    public static function make(string $text = ''): self
    {
        return new self(text: $text);
    }

    /**
     * @param  array<int, string>  $chunks
     */
    public function withChunks(array $chunks): self
    {
        return new self(
            text: $this->text,
            chunks: $chunks,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toEvents(): array
    {
        $events = [];

        $events[] = [
            'type' => 'message_start',
            'message' => [
                'id' => 'fake-id',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'fake-model',
                'content' => [],
                'stop_reason' => null,
                'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            ],
        ];

        $events[] = [
            'type' => 'content_block_start',
            'index' => 0,
            'content_block' => ['type' => 'text', 'text' => ''],
        ];

        $chunks = $this->chunks !== [] ? $this->chunks : [$this->text];

        foreach ($chunks as $chunk) {
            $events[] = [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => $chunk],
            ];
        }

        $events[] = [
            'type' => 'content_block_stop',
            'index' => 0,
        ];

        $events[] = [
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn'],
            'usage' => ['output_tokens' => 0],
        ];

        $events[] = [
            'type' => 'message_stop',
        ];

        return $events;
    }
}
