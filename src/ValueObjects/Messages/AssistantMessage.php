<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects\Messages;

class AssistantMessage extends AbstractMessage
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => 'assistant',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->content,
                ],
            ],
        ];
    }
}
