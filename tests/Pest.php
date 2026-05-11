<?php

declare(strict_types=1);

use Tests\TestCase;
use Illuminate\Contracts\Events\Dispatcher;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

uses(TestCase::class)->in('Feature', 'Ai');

function makeProvider(array $config = []): BedrockProvider
{
    return new BedrockProvider(
        config: array_merge([
            'name' => 'bedrock',
            'driver' => 'bedrock',
            'key' => 'test-api-key',
            'region' => 'us-east-1',
        ], $config),
        events: app(Dispatcher::class),
    );
}
