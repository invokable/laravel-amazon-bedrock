<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;

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
        ->using('bedrock', config('bedrock.model'))
        ->withPrompt('Tell me about Amazon Bedrock')
        ->asStream();

    foreach ($response as $event) {
        dump($event);
    }
});
