# Amazon Bedrock driver for Laravel AI SDK

[![Maintainability](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/maintainability.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Code Coverage](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/coverage.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/invokable/laravel-amazon-bedrock)

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), enabling text generation and streaming via Anthropic Claude models on AWS Bedrock.

- **Features**: Text generation and streaming.
- **Supported Models**: Anthropic Claude Haiku / Sonnet / Opus 4 and later (default: Claude Sonnet 4.6).
- **Authentication**: Bedrock API key.
- **Cache Control**: Ephemeral cache always enabled on system prompts.

## Requirements

- PHP >= 8.4
- Laravel >= 12.x

## Installation

```shell
composer require revolution/laravel-amazon-bedrock
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
```

## Configuration

Add the `bedrock` driver to `config/ai.php`:

```php
// config/ai.php
'default' => 'bedrock',

'providers' => [
    'bedrock' => [
        'driver' => 'bedrock',
        'key'    => env('AWS_BEDROCK_API_KEY', ''),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
],
```

Set the required values in `.env`:

```dotenv
AWS_BEDROCK_API_KEY=your_api_key
AWS_DEFAULT_REGION=us-east-1
```

The Bedrock API key is obtained from the AWS Management Console.

### Optional config keys

| Key | Description | Default |
|---|---|---|
| `timeout` | HTTP request timeout in seconds | 30 |
| `max_tokens` | Default max tokens per request | 8096 |
| `models.text.default` | Default text model | `global.anthropic.claude-sonnet-4-6:0` |
| `models.text.cheapest` | Cheapest text model | `global.anthropic.claude-haiku-4-5-20251001-v1:0` |
| `models.text.smartest` | Smartest text model | `global.anthropic.claude-opus-4-6-v1:0` |

## Usage

### Text Generation

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'You are an expert at software development.',
)->prompt('Tell me about Laravel');

echo $response->text;
```

### Streaming

```php
use Laravel\Ai\Streaming\Events\TextDelta;
use function Laravel\Ai\agent;

$stream = agent(
    instructions: 'You are an expert at software development.',
)->stream('Tell me about Laravel');

foreach ($stream as $event) {
    if ($event instanceof TextDelta) {
        echo $event->delta;
    }
}
```

## Standalone Usage (Legacy)

The `Bedrock` facade is still available for use without the Laravel AI SDK.

```dotenv
AWS_BEDROCK_API_KEY=your_api_key
AWS_BEDROCK_MODEL=global.anthropic.claude-sonnet-4-5-20250929-v1:0
AWS_DEFAULT_REGION=us-east-1
```

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;

$response = Bedrock::text()
                   ->using(Bedrock::KEY, config('bedrock.model'))
                   ->withSystemPrompt('You are a helpful assistant.')
                   ->withPrompt('Tell me a joke about programming.')
                   ->asText();

echo $response->text;
```

### Conversation History

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;

$response = Bedrock::text()
                   ->withSystemPrompt('You are a helpful assistant.')
                   ->withMessages([
                       new UserMessage('What is JSON?'),
                       new AssistantMessage('JSON is a lightweight data format...'),
                   ])
                   ->withPrompt('Can you show me an example?')
                   ->asText();

echo $response->text;
```

### Streaming (Legacy)

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;

$stream = Bedrock::text()
                 ->using(Bedrock::KEY, config('bedrock.model'))
                 ->withSystemPrompt('You are a helpful assistant.')
                 ->withPrompt('Tell me a joke about programming.')
                 ->asStream();

foreach ($stream as $event) {
    if (data_get($event, 'type') === 'content_block_delta') {
        echo data_get($event, 'delta.text');
    }
}
```

## Testing (Legacy)

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;
use Revolution\Amazon\Bedrock\Testing\TextResponseFake;

it('can generate text', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20));

    $fake = Bedrock::fake([$fakeResponse]);

    $response = Bedrock::text()
        ->using(Bedrock::KEY, 'global.anthropic.claude-sonnet-4-5-20250929-v1:0')
        ->withPrompt('Who are you?')
        ->asText();

    expect($response->text)->toBe('Hello, I am Claude!');
});
```

## License

MIT
