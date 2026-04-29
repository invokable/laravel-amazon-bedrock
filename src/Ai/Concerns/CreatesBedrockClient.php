<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Providers\Provider;
use Psr\Http\Message\RequestInterface;

trait CreatesBedrockClient
{
    protected function client(Provider $provider, string $model, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $region = $config['region'] ?? 'us-east-1';

        $client = Http::baseUrl("https://bedrock-runtime.{$region}.amazonaws.com")
            ->acceptJson()
            ->timeout($timeout ?? (int) ($config['timeout'] ?? 30))
            ->throw();

        return $this->authenticate($client, $provider, $region);
    }

    /**
     * Apply authentication to the HTTP client.
     *
     * Supports three modes:
     * 1. SigV4 with explicit credentials (key + secret + optional token)
     * 2. Bearer token (Bedrock API key only)
     * 3. SigV4 with default AWS credential chain (IAM roles, env vars, instance profiles)
     */
    protected function authenticate(PendingRequest $client, Provider $provider, string $region, string $service = 'bedrock'): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $secret = $config['secret'] ?? null;
        $key = $provider->providerCredentials()['key'] ?? null;

        if (filled($secret)) {
            $credentials = new Credentials((string) $key, $secret, $config['token'] ?? null);

            return $this->withSigV4Signing($client, $credentials, $region, $service);
        }

        if (filled($key)) {
            return $client->withToken($key);
        }

        return $this->withDefaultAwsCredentials($client, $config, $region, $service);
    }

    /**
     * Add SigV4 signing middleware to the HTTP client.
     */
    protected function withSigV4Signing(PendingRequest $client, CredentialsInterface $credentials, string $region, string $service = 'bedrock'): PendingRequest
    {
        $signer = new SignatureV4($service, $region);

        return $client->withMiddleware(function (callable $handler) use ($signer, $credentials): callable {
            return function (RequestInterface $request, array $options) use ($handler, $signer, $credentials) {
                return $handler($signer->signRequest($request, $credentials), $options);
            };
        });
    }

    /**
     * Resolve credentials from the default AWS credential chain and apply SigV4 signing.
     */
    protected function withDefaultAwsCredentials(PendingRequest $client, array $config, string $region, string $service = 'bedrock'): PendingRequest
    {
        $provider = CredentialProvider::defaultProvider($config);
        $credentials = $provider()->wait();

        return $this->withSigV4Signing($client, $credentials, $region, $service);
    }

    /**
     * Create an HTTP client for the Bedrock Agent Runtime service.
     *
     * Used for features like reranking that use the bedrock-agent-runtime endpoint
     * instead of the bedrock-runtime endpoint.
     */
    protected function agentRuntimeClient(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $region = $config['region'] ?? 'us-east-1';

        $client = Http::baseUrl("https://bedrock-agent-runtime.{$region}.amazonaws.com")
            ->acceptJson()
            ->timeout($timeout ?? (int) ($config['timeout'] ?? 30))
            ->throw();

        return $this->authenticate($client, $provider, $region);
    }

    /**
     * Create an HTTP client for the Amazon Polly service.
     *
     * Used for text-to-speech (TTS) audio generation.
     * Polly is a separate AWS service from Bedrock and uses SigV4 signing with the "polly" service name.
     */
    protected function pollyClient(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();
        $region = $config['region'] ?? 'us-east-1';

        $client = Http::baseUrl("https://polly.{$region}.amazonaws.com")
            ->contentType('application/json')
            ->timeout($timeout ?? (int) ($config['timeout'] ?? 30))
            ->throw();

        return $this->authenticate($client, $provider, $region, 'polly');
    }

    protected function invokeUrl(string $model): string
    {
        return "model/{$model}/invoke";
    }

    protected function converseUrl(string $model): string
    {
        return "model/{$model}/converse";
    }

    protected function converseStreamUrl(string $model): string
    {
        return "model/{$model}/converse-stream";
    }
}
