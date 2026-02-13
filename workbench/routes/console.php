<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Streaming\Events\TextDelta;
use Revolution\Amazon\Bedrock\Facades\Bedrock;

use function Laravel\Ai\agent;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

// vendor/bin/testbench bedrock:test
Artisan::command('bedrock:test {prompt?}', function (?string $prompt = null) {
    $response = Bedrock::text()
        ->using('bedrock', config('bedrock.model'))
        ->withPrompt('Tell me about Amazon Bedrock')
        ->asText();

    $this->info($response->text);
});

// vendor/bin/testbench bedrock:ai-sdk
Artisan::command('bedrock:ai-sdk', function () {
    $response = agent(
        instructions: 'You are an expert at software development.',
    )->prompt('Tell me about Laravel');

    $this->info($response->text);
});

// vendor/bin/testbench bedrock:stream
Artisan::command('bedrock:stream', function () {
    $response = Bedrock::text()
        ->using('bedrock', 'global.anthropic.claude-haiku-4-5-20251001-v1:0')
        ->withPrompt('Tell me about Amazon Bedrock')
        ->asStream();

    foreach ($response as $event) {
        if ($event['type'] === 'content_block_delta') {
            echo data_get($event, 'delta.text');
        }
    }
});

// vendor/bin/testbench bedrock:ai-sdk-stream
Artisan::command('bedrock:ai-sdk-stream', function () {
    $stream = agent(
        instructions: 'You are an expert at software development.',
    )->stream('Tell me about Laravel');

    foreach ($stream as $event) {
        if ($event instanceof TextDelta) {
            echo $event->delta;
        }
    }
});
