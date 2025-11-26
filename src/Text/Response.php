<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Text;

use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

readonly class Response
{
    public function __construct(
        public string $text,
        public string $finishReason,
        public Usage $usage,
        public Meta $meta,
    ) {}
}
