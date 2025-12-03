<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Text;

use Exception;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Revolution\Amazon\Bedrock\Testing\BedrockFake;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AbstractMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\SystemMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

class PendingRequest
{
    protected ?string $model = null;

    protected ?int $maxTokens = null;

    protected int|float|null $temperature = null;

    /**
     * @var array<int, string|SystemMessage>
     */
    protected array $systemPrompts = [];

    /**
     * @var array<int, AbstractMessage>
     */
    protected array $messages = [];

    protected ?string $prompt = null;

    public function __construct(
        protected ?BedrockFake $fake = null,
    ) {
    }

    /**
     * @param  string  $provider  Ignored, for Prism compatibility
     */
    public function using(string $provider, string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function withSystemPrompt(string|SystemMessage $message): self
    {
        $this->systemPrompts[] = $message;

        return $this;
    }

    /**
     * @param  array<int, string|SystemMessage>  $messages
     */
    public function withSystemPrompts(array $messages): self
    {
        $this->systemPrompts = $messages;

        return $this;
    }

    /**
     * @param  array<int, AbstractMessage>  $messages
     */
    public function withMessages(array $messages): self
    {
        $this->messages = $messages;

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
        if (filled($this->fake)) {
            $this->fake->record([
                'model' => $this->model ?? Config::string('bedrock.model'),
                'systemPrompts' => $this->systemPrompts,
                'messages' => $this->messages,
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
            'messages' => $this->buildMessages(),
        ];

        if (filled($this->systemPrompts)) {
            $body['system'] = $this->buildSystemPrompts();
        }

        if (filled($this->temperature)) {
            $body['temperature'] = $this->temperature;
        }

        return $body;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildMessages(): array
    {
        $messages = [];

        foreach ($this->messages as $message) {
            if ($message instanceof SystemMessage) {
                $messages[] = UserMessage::make($message->content)->toArray();
            } elseif ($message instanceof Arrayable) {
                $messages[] = $message->toArray();
            } elseif (is_array($message)) {
                $messages[] = $message;
            }
        }

        if (filled($this->prompt)) {
            $messages[] = UserMessage::make($this->prompt)->toArray();
        }

        return $messages;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildSystemPrompts(): array
    {
        return array_map(
            fn (string|SystemMessage $content): array => $content instanceof SystemMessage
                ? $content->toArray()
                : SystemMessage::make($content)->toArray(),
            $this->systemPrompts,
        );
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
