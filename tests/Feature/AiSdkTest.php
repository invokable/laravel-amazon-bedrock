<?php

declare(strict_types=1);

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use Laravel\Ai\StructuredAnonymousAgent;
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
            schema: fn (\Illuminate\Contracts\JsonSchema\JsonSchema $schema) => [
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
});
