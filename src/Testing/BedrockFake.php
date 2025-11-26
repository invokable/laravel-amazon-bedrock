<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Testing;

use Exception;
use PHPUnit\Framework\Assert as PHPUnit;
use Revolution\Amazon\Bedrock\Text\Response;
use Revolution\Amazon\Bedrock\ValueObjects\Meta;
use Revolution\Amazon\Bedrock\ValueObjects\Usage;

class BedrockFake
{
    protected int $responseSequence = 0;

    /**
     * @var array<int, array<string, mixed>>
     */
    protected array $recorded = [];

    /**
     * @param  array<int, Response>  $responses
     */
    public function __construct(
        protected array $responses = [],
    ) {}

    /**
     * @param  array<string, mixed>  $request
     */
    public function record(array $request): void
    {
        $this->recorded[] = $request;
    }

    public function nextResponse(): Response
    {
        if ($this->responses === []) {
            return new Response(
                text: '',
                finishReason: 'end_turn',
                usage: new Usage(0, 0),
                meta: new Meta('fake-id', 'fake-model'),
            );
        }

        $sequence = $this->responseSequence;

        if (! isset($this->responses[$sequence])) {
            throw new Exception('Could not find a response for the request');
        }

        $this->responseSequence++;

        return $this->responses[$sequence];
    }

    /**
     * @param  callable(array<int, array<string, mixed>>): void  $fn
     */
    public function assertRequest(callable $fn): void
    {
        $fn($this->recorded);
    }

    public function assertPrompt(string $prompt): void
    {
        $prompts = collect($this->recorded)
            ->pluck('prompt');

        PHPUnit::assertTrue(
            $prompts->contains($prompt),
            "Could not find the prompt '{$prompt}' in the recorded requests"
        );
    }

    public function assertSystemPrompt(string $systemPrompt): void
    {
        $systemPrompts = collect($this->recorded)
            ->pluck('systemPrompts')
            ->flatten();

        PHPUnit::assertTrue(
            $systemPrompts->contains($systemPrompt),
            "Could not find the system prompt '{$systemPrompt}' in the recorded requests"
        );
    }

    public function assertCallCount(int $expectedCount): void
    {
        $actualCount = count($this->recorded);

        PHPUnit::assertSame(
            $expectedCount,
            $actualCount,
            "Expected {$expectedCount} calls, got {$actualCount}"
        );
    }
}
