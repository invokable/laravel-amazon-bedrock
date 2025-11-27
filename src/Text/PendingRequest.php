<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Text;

use Exception;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Revolution\Amazon\Bedrock\Testing\BedrockFake;
use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

class PendingRequest
{
    protected ?string $model = null;

    protected ?int $maxTokens = null;

    protected int|float|null $temperature = null;

    /**
     * @var array<int, string>
     */
    protected array $systemPrompts = [];

    protected ?string $prompt = null;

    public function __construct(
        protected ?BedrockFake $fake = null,
    ) {}

    /**
     * @param  string  $provider  Ignored, for Prism compatibility
     */
    public function using(string $provider, string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function withSystemPrompt(string $message): self
    {
        $this->systemPrompts[] = $message;

        return $this;
    }

    public function withSystemPrompts(array $messages): self
    {
        $this->systemPrompts = $messages;

        return $this;
    }

    public function withPrompt(string $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function withMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function usingTemperature(int|float $temperature): self
    {
        $this->temperature = $temperature;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function asText(): Response
    {
        if ($this->fake !== null) {
            $this->fake->record([
                'model' => $this->model ?? Config::string('bedrock.model'),
                'systemPrompts' => $this->systemPrompts,
                'prompt' => $this->prompt,
                'maxTokens' => $this->maxTokens ?? Config::integer('bedrock.max_tokens'),
                'temperature' => $this->temperature,
            ]);

            return $this->fake->nextResponse();
        }

        $response = $this->sendRequest();

        return $this->parseResponse($response);
    }

    protected function sendRequest(): HttpResponse
    {
        $model = $this->model ?? Config::string('bedrock.model');
        $region = Config::string('bedrock.region');
        $apiKey = Config::string('bedrock.api_key');
        $timeout = Config::integer('bedrock.timeout', 30);

        $url = "https://bedrock-runtime.{$region}.amazonaws.com/model/{$model}/invoke";

        $body = $this->buildRequestBody();

        return Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->post($url, $body)
            ->throw();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRequestBody(): array
    {
        $body = [
            'anthropic_version' => Config::string('bedrock.anthropic_version'),
            'max_tokens' => $this->maxTokens ?? Config::integer('bedrock.max_tokens'),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $this->prompt,
                ],
            ],
        ];

        if ($this->systemPrompts !== []) {
            $body['system'] = array_map(
                fn (string $content): array => [
                    'type' => 'text',
                    'text' => $content,
                    'cache_control' => [
                        'type' => 'ephemeral',
                    ],
                ],
                $this->systemPrompts,
            );
        }

        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }

        return $body;
    }

    protected function parseResponse(HttpResponse $response): Response
    {
        $data = $response->json();

        $text = data_get($data, 'content.0.text', '');
        $finishReason = data_get($data, 'stop_reason', '');

        $usage = new Usage(
            promptTokens: data_get($data, 'usage.input_tokens', 0),
            completionTokens: data_get($data, 'usage.output_tokens', 0),
        );

        $meta = new Meta(
            id: data_get($data, 'id', ''),
            model: data_get($data, 'model', $this->model ?? Config::string('bedrock.model')),
        );

        return new Response(
            text: $text,
            finishReason: $finishReason,
            usage: $usage,
            meta: $meta,
        );
    }
}
