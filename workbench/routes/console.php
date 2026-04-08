<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Streaming\Events\TextDelta;

use function Laravel\Ai\agent;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

// vendor/bin/testbench bedrock:ai-sdk
Artisan::command('bedrock:ai-sdk', function () {
    $response = agent(
        instructions: 'You are an expert at software development.',
    )->prompt('Tell me about Laravel');

    $this->info($response->text);
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
