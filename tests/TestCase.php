<?php

declare(strict_types=1);

namespace Tests;

use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Revolution\Amazon\Bedrock\BedrockServiceProvider;
use Revolution\Amazon\Bedrock\Bedrock;

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
        $app['config']->set('ai.default', Bedrock::KEY);
        $app['config']->set('ai.providers', [
            Bedrock::KEY => [
                'driver' => Bedrock::KEY,
                'key' => 'test-api-key',
                'region' => 'us-east-1',
            ],
        ]);
    }
}
