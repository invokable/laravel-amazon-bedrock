<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Revolution\Amazon\Bedrock\Facades\Bedrock;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\UserMessage;
use Revolution\Amazon\Bedrock\ValueObjects\Messages\AssistantMessage;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

// vendor/bin/testbench bedrock:test
Artisan::command('bedrock:test {prompt?}', function (?string $prompt = null) {
    $response = Bedrock::text()
        ->using('bedrock', config('bedrock.model'))
        ->withSystemPrompt('You are running on Amazon Bedrock and Anthropic Claude model: '.config('bedrock.model'))
        //->withSystemPrompt('Always respond in Japanese.')
//        ->withPrompt($prompt)
        ->withMessages([
            new UserMessage('What is JSON?'),
            new AssistantMessage('JSON is a lightweight data format...'),
        ])
        ->withPrompt('Can you show me an example?')
//        ->withPrompt('What is JSON?')
        ->asText();

    $this->info($response->text);
});
