<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects\Messages;

class UserMessage extends AbstractMessage
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'text',
                    'text' => $this->content,
                ],
            ],
        ];
    }
}
