<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\ValueObjects;

readonly class Meta
{
    public function __construct(
        public string $id,
        public string $model,
    ) {}
}
