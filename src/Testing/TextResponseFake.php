<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Testing;

use Revolution\Amazon\Bedrock\Text\Response;
use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

readonly class TextResponseFake extends Response
{
    public static function make(): self
    {
        return new self(
            text: '',
            finishReason: 'end_turn',
            usage: new Usage(0, 0),
            meta: new Meta('fake-id', 'fake-model'),
        );
    }

    public function withText(string $text): self
    {
        return new self(
            text: $text,
            finishReason: $this->finishReason,
            usage: $this->usage,
            meta: $this->meta,
        );
    }

    public function withFinishReason(string $finishReason): self
    {
        return new self(
            text: $this->text,
            finishReason: $finishReason,
            usage: $this->usage,
            meta: $this->meta,
        );
    }

    public function withUsage(Usage $usage): self
    {
        return new self(
            text: $this->text,
            finishReason: $this->finishReason,
            usage: $usage,
            meta: $this->meta,
        );
    }

    public function withMeta(Meta $meta): self
    {
        return new self(
            text: $this->text,
            finishReason: $this->finishReason,
            usage: $this->usage,
            meta: $meta,
        );
    }
}
