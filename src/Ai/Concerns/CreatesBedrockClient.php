<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;

trait CreatesBedrockClient
{
    protected function client(Provider $provider, string $model, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $region = $config['region'] ?? 'us-east-1';

        return Http::baseUrl("https://bedrock-runtime.{$region}.amazonaws.com")
            ->withToken($provider->providerCredentials()['key'])
            ->acceptJson()
            ->timeout($timeout ?? (int) ($config['timeout'] ?? 30))
            ->throw();
    }

    protected function invokeUrl(string $model): string
    {
        return "model/{$model}/invoke";
    }

    protected function streamUrl(string $model): string
    {
        return "model/{$model}/invoke-with-response-stream";
    }
}
