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
        $app['config']->set('ai.default', 'bedrock');
        $app['config']->set('ai.providers', [
            'bedrock' => [
                'driver' => 'bedrock',
                'key' => 'test-api-key',
                'region' => 'us-east-1',
            ],
        ]);
    }
}
