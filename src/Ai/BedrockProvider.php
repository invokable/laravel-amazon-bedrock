<?php

namespace Revolution\Amazon\Bedrock\Ai;

use Laravel\Ai\Contracts\Providers\FileProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasFileGateway;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\ManagesFiles;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

/**
 * Laravel AI SDK Integration.
 */
class BedrockProvider extends Provider implements FileProvider, TextProvider
{
    use GeneratesText;
    use HasFileGateway;
    use HasTextGateway;
    use ManagesFiles;
    use StreamsText;

    /**
     * Get the name of the default text model.
     */
    public function defaultTextModel(): string
    {
        return 'global.anthropic.claude-sonnet-4-5-20250929-v1:0';
    }

    /**
     * Get the name of the cheapest text model.
     */
    public function cheapestTextModel(): string
    {
        return 'global.anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    /**
     * Get the name of the smartest text model.
     */
    public function smartestTextModel(): string
    {
        return 'global.anthropic.claude-opus-4-5-20251101-v1:0';
    }
}
