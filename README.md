# Amazon Bedrock driver for Laravel AI SDK

[![Maintainability](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/maintainability.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Code Coverage](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/coverage.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/invokable/laravel-amazon-bedrock)

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://laravel.com/docs/ai-sdk), enabling text generation, streaming, tool use (function calling), structured output, embeddings, image generation, audio (TTS), transcription (STT), and reranking via models on AWS Bedrock.

| Feature            | API key | Supported Models                                                                                              |
|--------------------|---------|---------------------------------------------------------------------------------------------------------------|
| Text, Streaming    | ✅       | Anthropic Claude, Amazon Nova, Meta Llama, Mistral, Cohere Command R, DeepSeek, AI21 Jamba (via Converse API) |
| Tool Use           | ✅       | Anthropic Claude, Amazon Nova, Meta Llama 3.1+, Mistral Large, Cohere Command R                               |
| Structured Output  | ✅       | Anthropic Claude, Amazon Nova, Meta Llama 3.1+, Mistral Large, Cohere Command R                               |
| Images             | ✅       | Stability AI models (default), Amazon Nova Canvas (deprecated).                                               |
| Audio(TTS)         | ❌       | Amazon Polly (generative, neural, long-form, standard engines)                                                |
| Transcription(STT) | ❌       | Amazon Nova 2 Lite (via Converse API AudioBlock)                                                              |
| Embeddings         | ✅       | Amazon Titan Embeddings V2 (default), Cohere Embed English/Multilingual V3, Cohere Embed V4 (batch support).  |
| Reranking          | ❌       | Cohere Rerank 3.5, Amazon Rerank 1.0                                                                          |
| Files              | —       | Not supported (Bedrock has no server-side file storage API)                                                   |

- **Authentication**: Bedrock API key, AWS IAM credentials (SigV4), or default AWS credential chain (IAM roles, instance profiles, etc.).
- **Failover**: Supports the AI SDK's multi-provider failover. Rate limit (429), overload (503, 529), and credit errors are mapped to failoverable exceptions.
- **Cache Control**: Ephemeral cache always enabled on system prompts (Anthropic models).
- **Multi-model**: Anthropic Claude uses the native Anthropic Messages API; all other models use the [Bedrock Converse API](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference.html) for a unified interface.

## Requirements

- PHP >= 8.3
- Laravel >= 12.x

## Installation

```shell
composer require revolution/laravel-amazon-bedrock
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
```

## Configuration

Add the `amazon-bedrock` driver to `config/ai.php`:

### Option 1: Bedrock API Key

```php
// config/ai.php
'default' => 'amazon-bedrock',
'default_for_images' => 'amazon-bedrock',
'default_for_audio' => 'amazon-bedrock',
'default_for_embeddings' => 'amazon-bedrock',
'default_for_reranking' => 'amazon-bedrock',
'default_for_transcription' => 'amazon-bedrock',

'providers' => [
    'amazon-bedrock' => [
        'driver' => 'amazon-bedrock',
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
> The Bedrock API key can only be used with the Bedrock Runtime API. It cannot be used with `bedrock-agent-runtime` (reranking) or Amazon Polly (audio/TTS). Use SigV4 or the default AWS credential chain for these features.

### Option 2: AWS IAM Credentials (SigV4)

Use AWS access key and secret key with [Signature Version 4](https://docs.aws.amazon.com/general/latest/gr/signature-version-4.html) signing:

```php
// config/ai.php
'providers' => [
    'amazon-bedrock' => [
        'driver' => 'amazon-bedrock',
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
    'amazon-bedrock' => [
        'driver' => 'amazon-bedrock',
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
| `models.text.smartest`         | Smartest text model             | `global.anthropic.claude-opus-4-7`                |
| `models.embeddings.default`    | Default embeddings model        | `amazon.titan-embed-text-v2:0`                    |
| `models.embeddings.dimensions` | Default embedding dimensions    | `1024`                                            |
| `models.image.default`         | Default image model             | `stability.stable-image-core-v1:1`                |
| `models.audio.default`         | Default audio (TTS) engine      | `generative`                                      |
| `models.reranking.default`     | Default reranking model         | `cohere.rerank-v3-5:0`                            |
| `models.transcription.default` | Default transcription model     | `us.amazon.nova-2-lite-v1:0`                      |

## Text Generation

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

### Conversation History

Maintain multi-turn conversations by implementing the `Conversational` interface in your agent class. The `messages()` method should return the previous conversation messages, which will be automatically included in each prompt:

```php
<?php

namespace App\Ai\Agents;

use App\Models\ChatHistory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

class ChatAgent implements Agent, Conversational
{
    use Promptable;

    public function __construct(public int $userId) {}

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    public function messages(): iterable
    {
        return ChatHistory::where('user_id', $this->userId)
            ->latest()
            ->limit(20)
            ->get()
            ->reverse()
            ->map(fn ($m) => new Message($m->role, $m->content))
            ->all();
    }
}
```

```php
$response = (new ChatAgent(auth()->id()))->prompt('What did we discuss earlier?');
```

#### Automatic Conversation Storage with `RemembersConversations`

For fully automatic conversation persistence (no manual `messages()` implementation needed), use the `RemembersConversations` trait. This requires the AI SDK database tables — run `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider" && php artisan migrate` first.

```php
<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Promptable;

class ChatAgent implements Agent, Conversational
{
    use Promptable, RemembersConversations;

    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }
}
```

Start a new conversation for a user:

```php
$response = (new ChatAgent)->forUser($user)->prompt('Hello!');

$conversationId = $response->conversationId;
```

Continue an existing conversation:

```php
$response = (new ChatAgent)
    ->continue($conversationId, as: $user)
    ->prompt('Tell me more about that.');
```

The Bedrock driver automatically includes conversation history in the Anthropic Messages API and Bedrock Converse API requests, so all supported models benefit from multi-turn conversation context.

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
        'name' => $schema->string()->description('The person\'s full name'),
        'age' => $schema->integer()->description('The person\'s age'),
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

## Image Generation

Generate images using Stability AI models (default) or Amazon Nova Canvas:

```php
use Laravel\Ai\Image;
use Revolution\Amazon\Bedrock\Bedrock;

// Uses Stability AI Stable Image Core by default
$response = Image::of('A cute steampunk robot')->generate(provider: Bedrock::KEY);

// Get the first image
$image = $response->firstImage();

// Store image to disk
$response->store('images', 's3');

// Render as HTML <img> tag
echo $response->toHtml('Steampunk robot');
```

<img src="art/bedrock-image-1776081529_nova-canvas.png" alt="A cute steampunk robot reading a book in a cozy library" height="300" width="300"/>

Available Stability AI models (all require `us-west-2` region):

```php
// Stable Image Core — fast and affordable (default)
$response = Image::of('A landscape')
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-core-v1:1');

// Stable Diffusion 3.5 Large — high quality, high quantity
$response = Image::of('A portrait')
    ->generate(provider: Bedrock::KEY, model: 'stability.sd3-5-large-v1:0');

// Stable Image Ultra — ultra-realistic, highest quality
$response = Image::of('A luxury product')
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-ultra-v1:1');
```

> [!NOTE]
> All Stability AI image models are available in `us-west-2` only. Configure `AWS_DEFAULT_REGION=us-west-2` when using these models.

### Image Editing with Stability AI

Stability AI Image Services editing models are also supported via the `attachments()` method. Pass an input image and use an editing model to transform it:

```php
use Laravel\Ai\Files\Image as ImageFile;
use Revolution\Amazon\Bedrock\Bedrock;

$inputImage = ImageFile::fromPath('/path/to/photo.jpg');

// Inpaint — fill in or replace areas using a mask or alpha channel
$response = Image::of('Replace the background with a forest')
    ->attachments([$inputImage])
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-inpaint-v1:0');

// Erase — remove unwanted elements from an image
$response = Image::of('')
    ->attachments([$inputImage])
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-erase-object-v1:0');

// Remove background — isolate the subject
$response = Image::of('')
    ->attachments([$inputImage])
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-remove-background-v1:0');

// Search and replace — replace an object described in the prompt
$response = Image::of('a cat')
    ->attachments([$inputImage])
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-image-search-replace-v1:0');

// Style transfer — apply a style from the prompt
$response = Image::of('Oil painting style')
    ->attachments([$inputImage])
    ->generate(provider: Bedrock::KEY, model: 'stability.stable-style-transfer-v1:0');
```

Available Stability AI editing models (all available in `us-east-1`, `us-east-2`, `us-west-2`):

| Model ID | Description |
|----------|-------------|
| `stability.stable-image-inpaint-v1:0` | Inpaint — fill/replace selected areas |
| `stability.stable-outpaint-v1:0` | Outpaint — expand the image beyond its borders |
| `stability.stable-image-erase-object-v1:0` | Erase — remove objects from an image |
| `stability.stable-image-remove-background-v1:0` | Remove background |
| `stability.stable-image-search-replace-v1:0` | Search and Replace — replace a described object |
| `stability.stable-image-search-recolor-v1:0` | Search and Recolor — change an object's color |
| `stability.stable-image-style-guide-v1:0` | Style Guide — apply a style reference image |
| `stability.stable-style-transfer-v1:0` | Style Transfer — transfer an art style |
| `stability.stable-image-control-sketch-v1:0` | Control Sketch — generate from a sketch |
| `stability.stable-image-control-structure-v1:0` | Control Structure — follow a structural guide |
| `stability.stable-creative-upscale-v1:0` | Creative Upscale — upscale with reimagining |
| `stability.stable-conservative-upscale-v1:0` | Conservative Upscale — upscale preserving detail |
| `stability.stable-fast-upscale-v1:0` | Fast Upscale — lightweight 4× upscaling |

Amazon Nova Canvas is also supported but is being deprecated by AWS:

```php
// Nova Canvas (deprecated — available in us-east-1, ap-northeast-1, eu-west-1)
$response = Image::of('A sunset')
    ->size('3:2')           // '1:1', '3:2', or '2:3'
    ->quality('high')       // 'low', 'medium', or 'high' (Nova Canvas only)
    ->generate(provider: Bedrock::KEY, model: 'amazon.nova-canvas-v1:0');
```

## Audio (TTS)

Generate speech audio from text using [Amazon Polly](https://docs.aws.amazon.com/polly/latest/dg/what-is.html):

```php
use Laravel\Ai\Audio;
use Revolution\Amazon\Bedrock\Bedrock;

$response = Audio::of('I love coding with Laravel.')->generate(provider: Bedrock::KEY);

$rawContent = (string) $response;
```

Use male or female voice:

```php
$response = Audio::of('I love coding with Laravel.')
    ->female()
    ->generate(provider: 'bedrock');

$response = Audio::of('I love coding with Laravel.')
    ->male()
    ->generate(provider: Bedrock::KEY);
```

Use a specific [Polly voice](https://docs.aws.amazon.com/polly/latest/dg/voicelist.html):

```php
$response = Audio::of('I love coding with Laravel.')
    ->voice('Joanna')
    ->generate(provider: Bedrock::KEY);
```

Store the generated audio:

```php
$response = Audio::of('I love coding with Laravel.')->generate(provider: Bedrock::KEY);

$path = $response->store();
$path = $response->storeAs('audio.mp3');
```

Specify a different engine (model):

```php
// Available engines: generative (default), neural, long-form, standard
$response = Audio::of('I love coding with Laravel.')
    ->generate(provider: Bedrock::KEY, model: 'neural');
```

**Default voices:** `default-female` → Ruth, `default-male` → Matthew (both support the generative engine).

> [!WARNING]
> Amazon Polly is a separate AWS service from Bedrock. The Bedrock API key (bearer token) cannot be used with Polly. Use AWS IAM credentials (SigV4) or the default AWS credential chain instead.

## Transcription (STT)

Transcribe audio to text using [Amazon Nova 2 Lite](https://docs.aws.amazon.com/nova/latest/userguide/what-is-nova.html) via the Converse API AudioBlock:

```php
use Laravel\Ai\Transcription;
use Revolution\Amazon\Bedrock\Bedrock;

$response = Transcription::of('base64-encoded-audio-data')
    ->generate(provider: Bedrock::KEY);

echo $response->text;
```

From a file path:

```php
$response = Transcription::fromPath('/path/to/audio.mp3')
    ->generate(provider: Bedrock::KEY);
```

Specify language and enable speaker diarization:

```php
$response = Transcription::of($audioData)
    ->language('en')
    ->diarize()
    ->generate(provider: Bedrock::KEY);
```

Use a custom model:

```php
$response = Transcription::of($audioData)
    ->generate(provider: Bedrock::KEY, model: 'us.amazon.nova-2-pro-v1:0');
```

**Supported audio formats:** MP3, WAV, FLAC, OGG, WebM, AAC, M4A, Opus, MKA.

> [!WARNING]
> Transcription uses the Converse API AudioBlock, which sends audio to an LLM for transcription. The Bedrock API key (bearer token) cannot be used with the Converse API. Use AWS IAM credentials (SigV4) or the default AWS credential chain. Segment-level timestamps are not available with this approach — only the full transcription text is returned.

## Embeddings

Generate vector embeddings using Amazon Titan Embeddings V2:

```php
use Laravel\Ai\Embeddings;
use Revolution\Amazon\Bedrock\Bedrock;

$response = Embeddings::for(['Hello world', 'Foo bar'])->generate(provider: Bedrock::KEY);

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
$response = Embeddings::for(['Hello world'])->dimensions(512)->generate(provider: Bedrock::KEY);
```

Use a custom model:

```php
$response = Embeddings::for(['Hello world'])
    ->dimensions(1024)
    ->generate(provider: Bedrock::KEY, model: 'amazon.titan-embed-text-v2:0');
```

### Cohere Embed Models

Cohere Embed models are automatically detected and use a batch API — all inputs are sent in a single request instead of one request per input, making them more efficient for multiple texts.

```php
// Cohere Embed English V3
$response = Embeddings::for(['Hello world', 'Foo bar'])
    ->dimensions(1024)
    ->generate(provider: Bedrock::KEY, model: 'cohere.embed-english-v3');

// Cohere Embed Multilingual V3
$response = Embeddings::for(['Hello', 'こんにちは'])
    ->dimensions(1024)
    ->generate(provider: Bedrock::KEY, model: 'cohere.embed-multilingual-v3');

// Cohere Embed V4 (supports configurable output dimensions 256–1536)
$response = Embeddings::for(['Hello world'])
    ->dimensions(512)
    ->generate(provider: Bedrock::KEY, model: 'cohere.embed-v4');
```

> **Note:** Cohere Embed models do not return token counts — `$response->tokens` will always be `0`. Titan Embeddings uses one HTTP request per input, while Cohere models batch all inputs into a single request.

## Reranking

Rerank documents by relevance to a query using Cohere Rerank 3.5 or Amazon Rerank 1.0:

```php
use Laravel\Ai\Reranking;
use Revolution\Amazon\Bedrock\Bedrock;

$response = Reranking::of([
    'Laravel is a PHP web framework.',
    'Python is a programming language.',
    'Laravel provides elegant syntax for web development.',
])->rerank(query: 'What is Laravel?', provider: Bedrock::KEY);

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
    ->rerank(query: 'What is Laravel?', provider: Bedrock::KEY);
```

Use a custom model:

```php
$response = Reranking::of([...])
    ->rerank(query: 'Search query', provider: Bedrock::KEY, model: 'amazon.rerank-v1:0');
```

**Note:** The reranking API uses the `bedrock-agent-runtime` endpoint (not `bedrock-runtime`). Amazon Rerank 1.0 is not available in `us-east-1` — use Cohere Rerank 3.5 in that region.

## Testing

Supports the standard testing features of the AI SDK.

Although not mentioned in the official documentation, when using the `agent()` helper, you can mock it with `AnonymousAgent::fake()` or `StructuredAnonymousAgent::fake()`.

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

## License

MIT
