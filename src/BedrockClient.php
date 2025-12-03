<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock;

use Revolution\Amazon\Bedrock\Testing\BedrockFake;
use Revolution\Amazon\Bedrock\Text\PendingRequest;

class BedrockClient
{
    public function __construct(
        protected ?BedrockFake $fake = null,
    ) {
    }

    public function text(): PendingRequest
    {
        return new PendingRequest($this->fake);
    }
}
