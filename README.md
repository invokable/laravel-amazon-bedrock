# Amazon Bedrock driver for Laravel AI SDK

[![Maintainability](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/maintainability.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Code Coverage](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/coverage.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/invokable/laravel-amazon-bedrock)

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), enabling text generation, streaming, embeddings, and image generation via models on AWS Bedrock.

| Feature            | Supported Models                                                                |
|--------------------|---------------------------------------------------------------------------------|
| Text               | Anthropic Claude Haiku / Sonnet / Opus 4 and later (default: Claude Sonnet 4.6) |
| Images             | Amazon Nova Canvas (default), Stability AI models.                              |
| Audio(TTS)         |                                                                                 |
| Transcription(STT) |                                                                                 |
| Embeddings         | Amazon Titan Embeddings V2 (default), Cohere Embed English/Multilingual V3.     |
| Reranking          |                                                                                 |
| Files              |                                                                                 |

- **Authentication**: Bedrock API key. Other authentication methods are being planned.
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
'default_for_images' => 'bedrock',
'default_for_embeddings' => 'bedrock',

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

| Key                            | Description                     | Default                                           |
|--------------------------------|---------------------------------|---------------------------------------------------|
| `timeout`                      | HTTP request timeout in seconds | 30                                                |
| `max_tokens`                   | Default max tokens per request  | 8096                                              |
| `models.text.default`          | Default text model              | `global.anthropic.claude-sonnet-4-6:0`            |
| `models.text.cheapest`         | Cheapest text model             | `global.anthropic.claude-haiku-4-5-20251001-v1:0` |
| `models.text.smartest`         | Smartest text model             | `global.anthropic.claude-opus-4-6-v1:0`           |
| `models.embeddings.default`    | Default embeddings model        | `amazon.titan-embed-text-v2:0`                    |
| `models.embeddings.dimensions` | Default embedding dimensions    | `1024`                                            |
| `models.image.default`         | Default image model             | `amazon.nova-canvas-v1:0`                         |

## Usage

### Agent Class

Create an agent class using the Artisan command:

```shell
php artisan make:agent BedrockAgent
```

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class BedrockAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are an expert at software development.';
    }
}
```

```php
use App\Ai\Agents\BedrockAgent;

$response = (new BedrockAgent)->prompt('Tell me about Laravel');

echo $response->text;
```

### Anonymous Agent

For quick interactions without a dedicated class:

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'You are an expert at software development.',
)->prompt('Tell me about Laravel');

echo $response->text;
```

### Streaming

```php
use App\Ai\Agents\BedrockAgent;

Route::get('/stream', function () {
    return (new BedrockAgent)->stream('Tell me about Laravel');
});
```

Or iterate through the events manually:

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

### Provider Options

To pass Bedrock-specific options such as `anthropic_version`, implement `HasProviderOptions`:

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

class BedrockAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function instructions(): string
    {
        return 'You are an expert at software development.';
    }

    public function providerOptions(Lab|string $provider): array
    {
        return [
            'anthropic_version' => 'bedrock-2023-05-31',
        ];
    }
}
```

Supported provider options:

| Option              | Description                        | Default              |
|---------------------|------------------------------------|----------------------|
| `anthropic_version` | Anthropic API version for Bedrock  | `bedrock-2023-05-31` |
| `top_k`             | Top-K sampling parameter           | —                    |
| `top_p`             | Top-P (nucleus) sampling parameter | —                    |

### Agent Configuration

Configure text generation options using PHP attributes:

```php
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

#[MaxTokens(4096)]
#[Temperature(0.7)]
#[Timeout(120)]
class BedrockAgent implements Agent
{
    use Promptable;

    // ...
}
```

## Testing

Use `AnonymousAgent::fake()` to test agent interactions:

```php
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Prompts\AgentPrompt;

use function Laravel\Ai\agent;

it('can generate text', function () {
    AnonymousAgent::fake();

    $response = agent(
        instructions: 'You are an expert at software development.',
    )->prompt('Tell me about Laravel');

    AnonymousAgent::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->contains('Laravel');
    });
});
```

## Image Generation

Generate images using Amazon Nova Canvas:

```php
use Laravel\Ai\Image;

$response = Image::of('A cute steampunk robot')->generate(provider: 'bedrock');

// Get the first image
$image = $response->firstImage();

// Store image to disk
$response->store('images', 's3');

// Render as HTML <img> tag
echo $response->toHtml('Steampunk robot');
```

Specify size and quality:

```php
$response = Image::of('A beautiful landscape')
    ->size('3:2')           // '1:1', '3:2', or '2:3'
    ->quality('high')       // 'low', 'medium', or 'high' (high → Nova Canvas "premium")
    ->generate(provider: 'bedrock');
```

Use a custom model:

```php
$response = Image::of('A sunset')
    ->generate(provider: 'bedrock', model: 'stability.sd3-5-large-v1:0');
```

## Embeddings

Generate vector embeddings using Amazon Titan Embeddings V2:

```php
use Laravel\Ai\Embeddings;

$response = Embeddings::for(['Hello world', 'Foo bar'])->generate(provider: 'bedrock');

// Access first embedding vector
$vector = $response->first();

// Iterate all embeddings
foreach ($response as $embedding) {
    // $embedding is an array of float values
}

echo $response->tokens; // total token count
```

Specify custom dimensions (256, 512, or 1024 for Titan Embeddings V2):

```php
$response = Embeddings::for(['Hello world'])->dimensions(512)->generate(provider: 'bedrock');
```

Use a custom model:

```php
$response = Embeddings::for(['Hello world'])
    ->dimensions(1024)
    ->generate(provider: 'bedrock', model: 'amazon.titan-embed-text-v2:0');
```

## License

MIT
