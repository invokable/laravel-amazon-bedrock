<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Testing;

use Exception;
use Illuminate\Support\Facades\Config;
use Revolution\Amazon\Bedrock\Text\PendingRequest;
use Revolution\Amazon\Bedrock\Text\Response;

class PendingRequestFake extends PendingRequest
{
    public function __construct(
        protected BedrockFake $fake,
    ) {}

    /**
     * @throws Exception
     */
    public function asText(): Response
    {
        $this->fake->record([
            'model' => $this->model ?? Config::string('bedrock.model'),
            'systemPrompts' => $this->systemPrompts,
            'messages' => $this->messages,
            'prompt' => $this->prompt,
            'maxTokens' => $this->maxTokens ?? Config::integer('bedrock.max_tokens'),
            'temperature' => $this->temperature,
        ]);

        return $this->fake->nextResponse();
    }
}
