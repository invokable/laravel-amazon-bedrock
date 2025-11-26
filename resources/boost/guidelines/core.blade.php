## Tiny Amazon Bedrock wrapper for Laravel

A lightweight Laravel package to easily interact with Amazon Bedrock, specifically for generating text.

### Usage

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;

$response = Bedrock::text()
                   ->using(Bedrock::KEY, config('bedrock.model'))
                   ->withSystemPrompt('You are a helpful assistant.')
                   ->withPrompt('Tell me a joke about programming.')
                   ->asText();

echo $response->text;
```

### Testing

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
