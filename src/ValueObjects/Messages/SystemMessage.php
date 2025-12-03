<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects\Messages;

class SystemMessage extends AbstractMessage
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => 'text',
            'text' => $this->content,
            'cache_control' => [
                'type' => 'ephemeral',
            ],
        ];
    }
}
