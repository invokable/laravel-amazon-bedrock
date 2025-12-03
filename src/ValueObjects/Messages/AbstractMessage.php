<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects\Messages;

use Illuminate\Contracts\Support\Arrayable;
use Stringable;

abstract class AbstractMessage implements Arrayable, Stringable
{
    public function __construct(
        public readonly string $content,
    ) {}

    public static function make(string|self $content): self
    {
        return is_string($content) ? new static($content) : $content;
    }

    /**
     * @return array<string, mixed>
     */
    abstract public function toArray(): array;

    public function __toString(): string
    {
        return $this->content;
    }
}
