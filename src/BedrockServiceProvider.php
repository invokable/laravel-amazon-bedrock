<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock;

use Illuminate\Support\ServiceProvider;
use Revolution\Amazon\Bedrock\Text\PendingRequest;

class BedrockServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bedrock.php', 'bedrock');

        $this->app->scoped(BedrockClient::class);
        $this->app->scoped(PendingRequest::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/bedrock.php' => config_path('bedrock.php'),
            ], 'bedrock-config');
        }
    }
}
