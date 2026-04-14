<?php

declare(strict_types=1);

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Audio;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\AudioPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\Prompts\RerankingPrompt;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\RerankingResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\StructuredAnonymousAgent;
use Laravel\Ai\Transcription;
use Revolution\Amazon\Bedrock\Bedrock;

use function Laravel\Ai\agent;

describe('Laravel AI SDK', function () {
    test('agent helper', function () {
        AnonymousAgent::fake();

        $response = agent(
            instructions: 'You are an expert at software development.',
        )->prompt('Tell me about Laravel');

        AnonymousAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->contains('Laravel');
        });
    });

    test('structured agent helper', function () {
        StructuredAnonymousAgent::fake([
            ['name' => 'Alice', 'age' => 25],
        ]);

        $response = agent(
            instructions: 'Extract person information from the given text.',
            schema: fn (JsonSchema $schema) => [
                'name' => $schema->string('The person\'s full name'),
                'age' => $schema->integer('The person\'s age'),
            ],
        )->prompt('Alice is 25 years old.');

        expect($response['name'])->toBe('Alice');
        expect($response['age'])->toBe(25);

        StructuredAnonymousAgent::assertPrompted(function (AgentPrompt $prompt) {
            return $prompt->contains('Alice');
        });
    });

    test('Embeddings', function () {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) {
            return array_map(
                fn () => Embeddings::fakeEmbedding($prompt->dimensions),
                $prompt->inputs
            );
        })->preventStrayEmbeddings();

        $response = Embeddings::for([
            'Laravel is a PHP framework.',
        ])->dimensions(1536)
            ->generate(provider: Bedrock::KEY);

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return $prompt->contains('Laravel') && $prompt->dimensions === 1536;
        });
    });

    test('Reranking', function () {
        Reranking::fake();

        $response = Reranking::of([
            'Laravel is a PHP framework.',
            'Python is a programming language.',
        ])->rerank(query: 'What is Laravel?', provider: Bedrock::KEY);

        expect($response)->toBeInstanceOf(RerankingResponse::class);
        expect($response)->toHaveCount(2);

        Reranking::assertReranked(function (RerankingPrompt $prompt) {
            return $prompt->query === 'What is Laravel?';
        });
    });

    test('Audio', function () {
        Audio::fake();

        $response = Audio::of('I love coding with Laravel.')
            ->generate(provider: Bedrock::KEY);

        expect($response)->toBeInstanceOf(AudioResponse::class);

        Audio::assertGenerated(function (AudioPrompt $prompt) {
            return $prompt->contains('Laravel');
        });
    });

    test('Transcription', function () {
        Transcription::fake();

        $response = Transcription::of('fake-audio-data')
            ->language('en')
            ->generate(provider: Bedrock::KEY);

        expect($response)->toBeInstanceOf(TranscriptionResponse::class);

        Transcription::assertGenerated(function (TranscriptionPrompt $prompt) {
            return $prompt->language === 'en';
        });
    });
});
