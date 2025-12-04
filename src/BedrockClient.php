<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock;

use Revolution\Amazon\Bedrock\Text\PendingRequest;

class BedrockClient
{
    public function text(): PendingRequest
    {
        return app(PendingRequest::class);
    }
}
