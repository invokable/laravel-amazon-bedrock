<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Revolution\Amazon\Bedrock\BedrockServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            BedrockServiceProvider::class,
            AiServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('bedrock.region', 'us-east-1');
        $app['config']->set('bedrock.api_key', 'test-api-key');
        $app['config']->set('bedrock.model', 'anthropic.claude-sonnet-4-20250514-v1:0');
        $app['config']->set('bedrock.anthropic_version', 'bedrock-2023-05-31');
        $app['config']->set('bedrock.max_tokens', 2048);

        $app['config']->set('ai.default', 'bedrock-anthropic');
        $app['config']->set('ai.providers', [
            'bedrock-anthropic' => [
                'driver' => 'bedrock-anthropic',
                'key' => 'test-api-key',
            ],
        ]);
    }
}
