<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;

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
                MessageRole::ToolResult => $this->mapToolResultMessage($message, $mapped),
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

    protected function mapAssistantMessage(AssistantMessage|Message $message, array &$mapped): void
    {
        $content = [];
        $hasToolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty();

        if (filled($message->content)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->content,
            ];
        }

        if ($hasToolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => $toolCall->arguments,
                ];
            }
        }

        if (filled($content)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }
    }

    protected function mapToolResultMessage(ToolResultMessage|Message $message, array &$mapped): void
    {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'type' => 'tool_result',
                'tool_use_id' => $toolResult->id,
                'content' => $this->serializeToolResultOutput($toolResult->result),
            ];
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    protected function serializeToolResultOutput(mixed $output): string
    {
        return match (true) {
            is_string($output) => $output,
            is_array($output) => json_encode($output),
            default => strval($output),
        };
    }
}
