<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;

trait MapsMessages
{
    /**
     * Map AI SDK messages to Bedrock/Anthropic Messages API format.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapUserMessage($message, $mapped),
                MessageRole::Assistant => $this->mapAssistantMessage($message, $mapped),
                default => null,
            };
        }

        return $mapped;
    }

    protected function mapUserMessage(Message $message, array &$mapped): void
    {
        $mapped[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
            ],
        ];
    }

    protected function mapAssistantMessage(Message $message, array &$mapped): void
    {
        $mapped[] = [
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => $message->content],
            ],
        ];
    }
}
