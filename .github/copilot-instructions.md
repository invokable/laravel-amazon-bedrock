# Project Guidelines

## Overview

A lightweight Laravel package to easily interact with Amazon Bedrock, specifically for generating text.

- **Features**: Text Generation only.
- **Supported Model**: Anthropic Claude Haiku/Sonnet/Opus 4 and later.(Default: Sonnet 4.6)
- **Authentication**: Bedrock API Key only.
- **Cache Control**: Always enabled ephemeral cache at system prompt.
- **Minimal Dependencies**: No extra dependencies except Laravel framework.

## NEXT

Prismにバグがあったので作ったパッケージだったけどその後Laravel AI SDKが登場したのでAI SDKに特化したパッケージにすれば独自に作ってる機能を削除できるのでリニューアルする。

- いつでもPrismに戻れるように使い方の互換を残してたパッケージからLaravel AI SDK専用パッケージにする。
- Anthropic以外のモデルも使ってテキスト生成以外のImages、TTS、STT、Embeddings、Reranking、Filesも可能なら対応する。

GitHub Agentic Workflowsで少しずつ実行。

## Technology Stack

- **Language**: PHP 8.4+
- **Framework**: Laravel 12.x+
- **Testing**: Pest PHP 4.x
- **Code Quality**: Laravel Pint (PSR-12)

## Command
- `composer run test` - Run pest tests.
- `composer run lint` - Run pint code formatter.

## Development Guidelines

- Keep Prism compatibility in mind when making changes.
- cache_control can only be used up to 4 blocks, so only system prompts are supported.
  - Error message `A maximum of 4 blocks with cache_control may be provided.` 

## Testing

- Don't write test-only code inside production code. Use service containers to swap it in.

## Laravel AI SDK Integration

- Experimental implementation.
- Support only text generation. No other features are supported.

This is an opt-in feature only enabled when the Laravel AI SDK is installed.

```shell
composer require laravel/ai
php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"
```

Add the following configuration to `config/ai.php`.

```php
// config/ai.php
    'default' => 'bedrock-anthropic',

    'providers' => [
        'bedrock-anthropic' => [
            'driver' => 'bedrock-anthropic',
            'key' => '',
        ],
    ],
```

Usage with agent helper.

```php
use function Laravel\Ai\agent;

$response = agent(
    instructions: 'You are an expert at software development.',
)->prompt('Tell me about Laravel');

echo $response->text;
```

Streaming

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
