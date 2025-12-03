<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;

abstract class AbstractMessage implements Arrayable
{
    public function __construct(
        public readonly string $content,
    ) {
    }

    public static function make(string $content): self
    {
        return new static($content);
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;
}
