<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\TextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

class BedrockProvider extends Provider implements TextProvider
{
    use GeneratesText;
    use HasTextGateway;
    use StreamsText;

    public function __construct(
        protected array $config,
        protected Dispatcher $events,
    ) {}

    public function textGateway(): TextGateway
    {
        return $this->textGateway ??= new BedrockGateway;
    }

    public function defaultTextModel(): string
    {
        return $this->config['models']['text']['default'] ?? 'global.anthropic.claude-sonnet-4-6:0';
    }

    public function cheapestTextModel(): string
    {
        return $this->config['models']['text']['cheapest'] ?? 'global.anthropic.claude-haiku-4-5-20251001-v1:0';
    }

    public function smartestTextModel(): string
    {
        return $this->config['models']['text']['smartest'] ?? 'global.anthropic.claude-opus-4-6-v1:0';
    }
}
