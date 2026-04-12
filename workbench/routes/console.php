<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Image;
use Laravel\Ai\Reranking;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Tools\Request;
use Revolution\Amazon\Bedrock\Bedrock;

use function Laravel\Ai\agent;

// 開発環境でのテスト用コマンド。
// Bedrock APIキーで使えない機能は除く。
// configはworkbench内の.envとWorkbenchServiceProviderでセットしている。

// -----------------------------------------------------------------------
// Text Generation (Anthropic Claude via native API)
// vendor/bin/testbench bedrock:text
// -----------------------------------------------------------------------
Artisan::command('bedrock:text', function () {
    $response = agent(
        instructions: 'You are an expert at software development.',
    )->prompt('Tell me about Laravel in one sentence.', provider: Bedrock::KEY);

    $this->info($response->text);
})->purpose('Text generation with Anthropic Claude (native API)');

// -----------------------------------------------------------------------
// Streaming Text (Anthropic Claude via native API)
// vendor/bin/testbench bedrock:text-stream
// -----------------------------------------------------------------------
Artisan::command('bedrock:text-stream', function () {
    $stream = agent(
        instructions: 'You are an expert at software development.',
    )->stream('Tell me about Laravel in one sentence.', provider: Bedrock::KEY);

    foreach ($stream as $event) {
        if ($event instanceof TextDelta) {
            echo $event->delta;
        }
    }

    echo PHP_EOL;
})->purpose('Streaming text generation with Anthropic Claude (native API)');

// -----------------------------------------------------------------------
// Text Generation via Converse API (non-Anthropic model, e.g. Amazon Nova)
// vendor/bin/testbench bedrock:converse
// -----------------------------------------------------------------------
Artisan::command('bedrock:converse', function () {
    $response = agent(
        instructions: 'You are an expert at software development.',
    )->prompt(
        'Tell me about Laravel in one sentence.',
        provider: Bedrock::KEY,
        model: 'amazon.nova-lite-v1:0',
    );

    $this->info($response->text);
})->purpose('Text generation with Amazon Nova Lite (Converse API)');

// -----------------------------------------------------------------------
// Streaming Text via Converse API (non-Anthropic model, e.g. Amazon Nova)
// vendor/bin/testbench bedrock:converse-stream
// -----------------------------------------------------------------------
Artisan::command('bedrock:converse-stream', function () {
    $stream = agent(
        instructions: 'You are an expert at software development.',
    )->stream(
        'Tell me about Laravel in one sentence.',
        provider: Bedrock::KEY,
        model: 'amazon.nova-lite-v1:0',
    );

    foreach ($stream as $event) {
        if ($event instanceof TextDelta) {
            echo $event->delta;
        }
    }

    echo PHP_EOL;
})->purpose('Streaming text generation with Amazon Nova Lite (Converse API)');

// -----------------------------------------------------------------------
// Tool Use (Function Calling)
// vendor/bin/testbench bedrock:tools
// -----------------------------------------------------------------------
Artisan::command('bedrock:tools', function () {
    $tool = new class implements Tool
    {
        public function description(): string
        {
            return 'Get the current time for a given timezone.';
        }

        public function schema(JsonSchema $schema): array
        {
            return [
                $schema->string('timezone', 'A valid PHP timezone identifier, e.g. Asia/Tokyo.'),
            ];
        }

        public function handle(Request $request): string
        {
            $tz = $request->string('timezone', 'UTC');

            return now()->setTimezone($tz)->toDateTimeString();
        }
    };

    $response = agent(
        instructions: 'You are a helpful assistant.',
        tools: [$tool],
    )->prompt('What time is it now in Tokyo?', provider: Bedrock::KEY);

    $this->info($response->text);
})->purpose('Tool use / function calling');

// -----------------------------------------------------------------------
// Structured Output
// vendor/bin/testbench bedrock:structured
// -----------------------------------------------------------------------
Artisan::command('bedrock:structured', function () {
    $response = agent(
        instructions: 'Extract structured information from the text.',
        schema: fn (JsonSchema $schema) => [
            'name' => $schema->string("The person's full name"),
            'age' => $schema->integer("The person's age"),
            'occupation' => $schema->string("The person's occupation"),
        ],
    )->prompt(
        'Taylor Otwell is a 38-year-old software developer who created the Laravel framework.',
        provider: Bedrock::KEY,
    );

    $this->info(json_encode($response->structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
})->purpose('Structured output extraction');

// -----------------------------------------------------------------------
// Embeddings (Amazon Titan Embeddings V2)
// vendor/bin/testbench bedrock:embeddings
// -----------------------------------------------------------------------
Artisan::command('bedrock:embeddings', function () {
    $response = Embeddings::for(['Hello world', 'Laravel is awesome'])
        ->dimensions(256)
        ->generate(provider: Bedrock::KEY);

    $this->info('Number of embeddings: '.$response->count());
    $this->info('Dimensions of first vector: '.count($response->first()));
    $this->info('Total input tokens: '.$response->tokens);
})->purpose('Generate embeddings with Amazon Titan Embeddings V2');

// -----------------------------------------------------------------------
// Image Generation (Amazon Nova Canvas)
// vendor/bin/testbench bedrock:image
// -----------------------------------------------------------------------
Artisan::command('bedrock:image', function () {
    $response = Image::of('A cute robot reading a book in a cozy library')
        ->square()
        ->generate(provider: Bedrock::KEY);

    $image = $response->firstImage();
    $path = sys_get_temp_dir().'/bedrock-image-'.time().'.png';
    file_put_contents($path, $image->content());

    $this->info('Image saved to: '.$path);
    $this->info('MIME type: '.$image->mime);
})->purpose('Image generation with Amazon Nova Canvas');

// -----------------------------------------------------------------------
// Reranking (Cohere Rerank)
// vendor/bin/testbench bedrock:reranking
// -----------------------------------------------------------------------
// Artisan::command('bedrock:reranking', function () {
//    $documents = [
//        'Laravel is a PHP web application framework.',
//        'Python is a general-purpose programming language.',
//        'Eloquent ORM makes database interactions easy in Laravel.',
//        'React is a JavaScript library for building user interfaces.',
//        'Laravel Artisan is a command-line interface included with Laravel.',
//    ];
//
//    $response = Reranking::of($documents)
//        ->limit(3)
//        ->rerank('What is Laravel?', provider: Bedrock::KEY);
//
//    foreach ($response->results as $result) {
//        $this->line(sprintf('[%.4f] %s', $result->score, $result->document));
//    }
// })->purpose('Rerank documents with Cohere Rerank 3.5');
