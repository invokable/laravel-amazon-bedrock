# Amazon Bedrock driver for Laravel AI SDK

[![Maintainability](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/maintainability.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Code Coverage](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/coverage.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/invokable/laravel-amazon-bedrock)

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), enabling text generation, streaming, tool use (function calling), structured output, embeddings, and image generation via models on AWS Bedrock.

| Feature            | Supported Models                                                                                              |
|--------------------|---------------------------------------------------------------------------------------------------------------|
| Text, Streaming    | Anthropic Claude, Amazon Nova, Meta Llama, Mistral, Cohere Command R, DeepSeek, AI21 Jamba (via Converse API) |
| Tool Use           | Anthropic Claude, Amazon Nova, Meta Llama 3.1+, Mistral Large, Cohere Command R                               |
| Structured Output  | Anthropic Claude, Amazon Nova, Meta Llama 3.1+, Mistral Large, Cohere Command R                               |
| Images             | Amazon Nova Canvas (default), Stability AI models.                                                            |
| Audio(TTS)         |                                                                                                               |
| Transcription(STT) |                                                                                                               |
| Embeddings         | Amazon Titan Embeddings V2 (default), Cohere Embed English/Multilingual V3.                                   |
| Reranking          | Cohere Rerank 3.5, Amazon Rerank 1.0                                                                          |
| Files              |                                                                                                               |

- **Authentication**: Bedrock API key, AWS IAM credentials (SigV4), or default AWS credential chain (IAM roles, instance profiles, etc.).
- **Cache Control**: Ephemeral cache always enabled on system prompts (Anthropic models).
- **Multi-model**: Anthropic Claude uses the native Anthropic Messages API; all other models use the [Bedrock Converse API](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference.html) for a unified interface.

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

### Option 1: Bedrock API Key

```php
// config/ai.php
'default' => 'bedrock',
'default_for_images' => 'bedrock',
'default_for_embeddings' => 'bedrock',
'default_for_reranking' => 'bedrock',

'providers' => [
    'bedrock' => [
        'driver' => 'bedrock',
        'key'    => env('AWS_BEDROCK_API_KEY', ''),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
],
```

```dotenv
AWS_BEDROCK_API_KEY=your_api_key
AWS_DEFAULT_REGION=us-east-1
```

The Bedrock API key is obtained from the AWS Management Console.

> [!WARNING]
> The Bedrock API key can only be used with the Bedrock Runtime API. It cannot be used with `bedrock-agent-runtime`, such as for reranking.

### Option 2: AWS IAM Credentials (SigV4)

Use AWS access key and secret key with [Signature Version 4](https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html) signing:

```php
// config/ai.php
'providers' => [
    'bedrock' => [
        'driver' => 'bedrock',
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'token'  => env('AWS_SESSION_TOKEN'),  // optional, for temporary credentials
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
],
```

```dotenv
AWS_ACCESS_KEY_ID=AKIA...
AWS_SECRET_ACCESS_KEY=wJalr...
AWS_SESSION_TOKEN=         # optional, for STS temporary credentials
AWS_DEFAULT_REGION=us-east-1
```

### Option 3: Default AWS Credential Chain (IAM Roles)

For EC2 instances, ECS tasks, Lambda functions, or any environment with IAM roles — omit `key` and `secret` to use the [default AWS credential provider chain](https://docs.aws.amazon.com/sdkref/latest/guide/standardized-credentials.html):

```php
// config/ai.php
'providers' => [
    'bedrock' => [
        'driver' => 'bedrock',
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
],
```

```dotenv
AWS_DEFAULT_REGION=us-east-1
```

The default credential chain automatically resolves credentials from environment variables, shared credentials files (`~/.aws/credentials`), ECS task roles, EC2 instance profiles, and more.

### Optional config keys

| Key                            | Description                     | Default                                           |
|--------------------------------|---------------------------------|---------------------------------------------------|
| `secret`                       | AWS secret access key (SigV4)   | —                                                 |
| `token`                        | AWS session token (SigV4)       | —                                                 |
| `timeout`                      | HTTP request timeout in seconds | 30                                                |
| `max_tokens`                   | Default max tokens per request  | 8096                                              |
| `models.text.default`          | Default text model              | `global.anthropic.claude-sonnet-4-6`              |
| `models.text.cheapest`         | Cheapest text model             | `global.anthropic.claude-haiku-4-5-20251001-v1:0` |
| `models.text.smartest`         | Smartest text model             | `global.anthropic.claude-opus-4-6-v1`             |
| `models.embeddings.default`    | Default embeddings model        | `amazon.titan-embed-text-v2:0`                    |
| `models.embeddings.dimensions` | Default embedding dimensions    | `1024`                                            |
| `models.image.default`         | Default image model             | `amazon.nova-canvas-v1:0`                         |
| `models.reranking.default`     | Default reranking model         | `cohere.rerank-v3-5:0`                            |

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

### Tool Use (Function Calling)

Define tools that Claude can invoke during generation:

```php
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Illuminate\Contracts\JsonSchema\JsonSchema;

class GetWeather implements Tool
{
    public function description(): string
    {
        return 'Get current weather for a city.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'city' => $schema->string()->required()->description('The city name.'),
        ];
    }

    public function handle(Request $request): string
    {
        // Call your weather API here
        return json_encode(['temperature' => 22, 'condition' => 'sunny']);
    }
}
```

Use the tool with an agent:

```php
use App\Ai\Agents\BedrockAgent;
use App\Ai\Tools\GetWeather;
use Laravel\Ai\Attributes\MaxSteps;

#[MaxSteps(5)]
class BedrockAgent implements Agent
{
    use Promptable;

    public function tools(): array
    {
        return [new GetWeather];
    }

    public function instructions(): string
    {
        return 'You are a helpful weather assistant.';
    }
}

$response = (new BedrockAgent)->prompt('What is the weather in Tokyo?');
echo $response->text;
```

Or with an anonymous agent:

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'You are a helpful weather assistant.',
    tools: [new GetWeather],
    maxSteps: 5,
)->prompt('What is the weather in Tokyo?');
```

Tool calls also work with streaming — the SDK automatically executes tool calls and continues the conversation until the model produces a final text response.

### Structured Output

Get structured (typed) responses from Claude using the `HasStructuredOutput` interface:

```php
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

class ExtractPerson implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Extract person information from the given text.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person\'s full name'),
            'age' => $schema->integer()->description('The person\'s age'),
            'occupation' => $schema->string()->description('The person\'s occupation'),
        ];
    }
}

$response = (new ExtractPerson)->prompt('John is a 30-year-old software engineer.');

// Access structured data via array access
echo $response['name'];       // "John"
echo $response['age'];        // 30
echo $response['occupation']; // "software engineer"
```

Or with an anonymous structured agent:

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'Extract person information from the given text.',
    schema: fn (JsonSchema $schema) => [
        'name' => $schema->string('The person\'s full name'),
        'age' => $schema->integer('The person\'s age'),
    ],
)->prompt('Alice is 25 years old.');

echo $response['name']; // "Alice"
echo $response['age'];  // 25
```

Under the hood, the driver creates a synthetic tool (`output_structured_data`) that forces Claude to return data matching your schema. This approach is compatible with all Claude models on Bedrock and non-Anthropic models via the Converse API.

### Non-Anthropic Models (Converse API)

The driver automatically detects the model family and routes non-Anthropic models through the [Bedrock Converse API](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference.html). This provides access to Amazon Nova, Meta Llama, Mistral, Cohere, DeepSeek, and other models available on Bedrock — all through the same Laravel AI SDK interface.

```php
use function Laravel\Ai\agent;

// Amazon Nova
$response = agent(
    instructions: 'You are a helpful assistant.',
    model: 'amazon.nova-pro-v1:0',
)->prompt('Tell me about AWS.');

// Meta Llama
$response = agent(
    instructions: 'You are a helpful assistant.',
    model: 'meta.llama3-1-70b-instruct-v1:0',
)->prompt('Explain quantum computing.');

// Mistral
$response = agent(
    instructions: 'You are a helpful assistant.',
    model: 'mistral.mistral-large-2402-v1:0',
)->prompt('Write a haiku about coding.');

// Cohere Command R+
$response = agent(
    instructions: 'You are a helpful assistant.',
    model: 'cohere.command-r-plus-v1:0',
)->prompt('Summarize this text.');

// DeepSeek R1
$response = agent(
    instructions: 'You are a helpful assistant.',
    model: 'deepseek.r1-v1:0',
)->prompt('Solve this math problem.');
```

Streaming, tool use, and structured output work with Converse API models that support these features. See the [Bedrock supported models table](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-supported-models-features.html) for feature availability per model.

**API routing:**
- Anthropic Claude models (`anthropic.*`) → Anthropic Messages API (`/model/{id}/invoke`)
- All other models → Converse API (`/model/{id}/converse`)

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

| Option                         | Description                                | Default              |
|--------------------------------|--------------------------------------------|----------------------|
| `anthropic_version`            | Anthropic API version (Claude models only) | `bedrock-2023-05-31` |
| `top_k`                        | Top-K sampling parameter                   | —                    |
| `top_p`                        | Top-P (nucleus) sampling parameter         | —                    |
| `additionalModelRequestFields` | Converse API additional model parameters   | —                    |

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

## Reranking

Rerank documents by relevance to a query using Cohere Rerank 3.5 or Amazon Rerank 1.0:

```php
use Laravel\Ai\Reranking;

$response = Reranking::of([
    'Laravel is a PHP web framework.',
    'Python is a programming language.',
    'Laravel provides elegant syntax for web development.',
])->rerank(query: 'What is Laravel?', provider: 'bedrock');

// Get the top-ranked document
echo $response->first()->document; // "Laravel is a PHP web framework."
echo $response->first()->score;    // 0.95

// Get all documents in reranked order
foreach ($response as $result) {
    echo "{$result->index}: {$result->document} ({$result->score})\n";
}

// Limit the number of results
$response = Reranking::of([...])
    ->limit(2)
    ->rerank(query: 'What is Laravel?', provider: 'bedrock');
```

Use a custom model:

```php
$response = Reranking::of([...])
    ->rerank(query: 'Search query', provider: 'bedrock', model: 'amazon.rerank-v1:0');
```

**Note:** The reranking API uses the `bedrock-agent-runtime` endpoint (not `bedrock-runtime`). Amazon Rerank 1.0 is not available in `us-east-1` — use Cohere Rerank 3.5 in that region.

## License

MIT
