# Tiny Amazon Bedrock wrapper for Laravel

[![Maintainability](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/maintainability.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)
[![Code Coverage](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock/coverage.svg)](https://qlty.sh/gh/invokable/projects/laravel-amazon-bedrock)

## Overview

A lightweight Laravel package to easily interact with Amazon Bedrock, specifically for generating text.

- **Features**: Text Generation only.
- **Supported Model**: Anthropic Claude Haiku/Sonnet/Opus 4 and later.(Default: Sonnet 4.5)
- **Authentication**: Bedrock API Key only.
- **Cache Control**: Always enabled ephemeral cache at system prompt.
- **Minimal Dependencies**: No extra dependencies except Laravel framework.

We created our own package because `prism-php/bedrock` often doesn't support breaking changes in `prism-php/prism`. If you need more functionality than this package, please use [Prism](https://github.com/prism-php).

## Requirements

- PHP >= 8.4
- Laravel >= 12.x

## Installation

```shell
composer require revolution/laravel-amazon-bedrock
```

## Configuration

Publishing the config file is optional. Everything can be set in `.env`.

```dotenv
AWS_BEDROCK_API_KEY=your_api_key
AWS_BEDROCK_MODEL=global.anthropic.claude-sonnet-4-5-20250929-v1:0
AWS_DEFAULT_REGION=us-east-1
```

Bedrock API key is obtained from the AWS Management Console.

## Usage

Usage is almost the same, making it easy to return to Prism, but it doesn't have any other features.

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

For multi-turn conversations, use `withMessages()` to pass previous messages.

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

Example with Eloquent conversation history

```php
use App\Models\Message;
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;

$messages = Message::query()
    ->where('conversation_id', $conversationId)
    ->orderBy('created_at')
    ->get()
    ->map(fn (Message $message) => match ($message->role) {
        'user' => UserMessage::make($message->content),
        'assistant' => AssistantMessage::make($message->content),
    })
    ->all();

$response = Bedrock::text()
                   ->withSystemPrompt('You are a helpful assistant.')
                   ->withMessages($messages)
                   ->withPrompt($newUserMessage)
                   ->asText();
```

## Testing

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;
use Revolution\Amazon\Bedrock\Testing\TextResponseFake;

it('can generate text', function () {
    $fakeResponse = TextResponseFake::make()
        ->withText('Hello, I am Claude!')
        ->withUsage(new Usage(10, 20));

    // Set up the fake
    $fake = Bedrock::fake([$fakeResponse]);

    // Run your code
    $response = Bedrock::text()
        ->using(Bedrock::KEY, 'global.anthropic.claude-sonnet-4-5-20250929-v1:0')
        ->withPrompt('Who are you?')
        ->asText();

    // Make assertions
    expect($response->text)->toBe('Hello, I am Claude!');
});
```

## License

MIT
