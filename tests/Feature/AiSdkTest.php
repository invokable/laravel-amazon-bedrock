<?php

declare(strict_types=1);

use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

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
            ->generate(provider: 'bedrock');

        Embeddings::assertGenerated(function (EmbeddingsPrompt $prompt) {
            return $prompt->contains('Laravel') && $prompt->dimensions === 1536;
        });
    });
});
