<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Revolution\Amazon\Bedrock\Ai\BedrockProvider;

class BedrockServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Ai::extend('bedrock', function (Application $app, array $config) {
            return new BedrockProvider(
                $config,
                $this->app->make(Dispatcher::class),
            );
        });
    }
}
