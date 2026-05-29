<?php

declare(strict_types=1);

use Illuminate\Contracts\Events\Dispatcher;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;
use Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Ai');

function makeProvider(array $config = []): BedrockProvider
{
    return new BedrockProvider(
        config: array_merge([
            'name' => 'amazon-bedrock',
            'driver' => 'amazon-bedrock',
            'key' => 'test-api-key',
            'region' => 'us-east-1',
        ], $config),
        events: app(Dispatcher::class),
    );
}
