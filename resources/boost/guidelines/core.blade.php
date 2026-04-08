## Amazon Bedrock driver for Laravel AI SDK

An Amazon Bedrock driver for the Laravel AI SDK, enabling text generation and streaming via Anthropic Claude models.

### Configuration

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

### Standalone Usage (Legacy)

The `Bedrock` facade is still available without the AI SDK.

```php
use Revolution\Amazon\Bedrock\Facades\Bedrock;

$response = Bedrock::text()
                   ->using(Bedrock::KEY, config('bedrock.model'))
                   ->withSystemPrompt('You are a helpful assistant.')
                   ->withPrompt('Tell me a joke about programming.')
                   ->asText();

echo $response->text;
```
