<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Revolution\Amazon\Bedrock\Facades\Bedrock;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote');

Artisan::command('bedrock:test {prompt}', function (string $prompt) {
    $response = Bedrock::text()
        ->using('bedrock', config('bedrock.model'))
        ->withSystemPrompt('You are running on Amazon Bedrock and Anthropic Claude model: '.config('bedrock.model'))
        ->withSystemPrompt('Always respond in Japanese.')
        ->withPrompt($prompt)
        ->asText();

    $this->info($response->text);
});
