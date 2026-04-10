---
name: Laravel AI SDK Development
description: Investigates and incrementally implements new AI SDK features for the Amazon Bedrock driver. Reads discussions/3 as memory, explores what Bedrock can support beyond text generation, implements one feature at a time, and records progress.

on:
  schedule: daily around 17:00 utc+9
  workflow_dispatch:

permissions:
  contents: read
  discussions: read
  issues: read
  pull-requests: read

engine:
  id: copilot
  version: "1.0.21"
  model: claude-opus-4.6

checkout:
  ref: main

steps:
    - name: Set up PHP
      uses: shivammathur/setup-php@2.37.0
      with:
          php-version: 8.4
          extensions: mbstring
          coverage: xdebug

    - name: Install Composer dependencies
      run: composer install --no-interaction --prefer-dist --optimize-autoloader

tools:
  github:
    toolsets: [default, discussions]
  edit: {}
  bash: true
  web-fetch: {}

network:
  allowed:
    - github
    - threat-detection
    - php
    - "docs.aws.amazon.com"

safe-outputs:
  add-comment:
    target: "*"
    discussions: true
    issues: false
    pull-requests: false
    max: 1
  create-pull-request:
    base-branch: main
    labels: [ai-sdk]
    draft: true
    if-no-changes: warn
---

# Laravel AI SDK Feature Development

You are a PHP developer expanding the Amazon Bedrock driver for the Laravel AI SDK. The package currently supports text generation and streaming. Your goal is to investigate what additional AI SDK features Amazon Bedrock can support and implement them one at a time.

## Memory: Discussion #3

Read discussion #3 in the `invokable/laravel-amazon-bedrock` repository **first**. This is your persistent memory across runs. It tells you:
- What has already been investigated and implemented
- What was attempted and what worked or didn't
- What the next priority should be

If there are no previous comments about feature expansion, you are starting fresh.

## Research Phase

Before implementing anything, investigate what's possible. You need to cross-reference two things:

### 1. Laravel AI SDK Capabilities

Study the installed `laravel/ai` source code in `vendor/laravel/ai/src/` to understand:
- What provider interfaces exist beyond `TextProvider` (e.g., `ImageProvider`, `AudioProvider`, `TranscriptionProvider`, `EmbeddingProvider`, `RerankingProvider`, `FileProvider`)
- What gateway contracts each provider interface requires
- How existing providers (Anthropic, OpenAI, etc.) implement these features
- What response types and streaming patterns each feature uses

Also fetch the official documentation:
- `https://raw.githubusercontent.com/laravel/docs/13.x/ai-sdk.md`

### 2. Amazon Bedrock API Capabilities

Research what Amazon Bedrock supports. Use web search and fetch the Bedrock documentation:
- `https://docs.aws.amazon.com/bedrock/` — main documentation hub
- Look for: image generation (Stability AI, Amazon Titan Image), embeddings (Amazon Titan Embeddings, Cohere Embed), audio/TTS, transcription
- Understand the API endpoints, request/response formats, and authentication patterns
- Note which features use the same `bedrock-runtime` API vs different endpoints

### 3. Feasibility Analysis

For each AI SDK feature, determine:
- Does Bedrock offer an equivalent API?
- Which Bedrock models support it?
- Is the API format compatible enough to implement a gateway?
- What additional configuration would be needed?

## Implementation Phase

Pick **one** feature to implement per run. Choose based on:
1. What the discussion history says is next
2. What's most feasible given Bedrock's capabilities
3. What has the most value to users

### Implementation Guidelines

- Study how an existing provider implements the same feature in `vendor/laravel/ai/src/Gateway/` before writing code
- Follow the Concerns trait pattern established in `src/Ai/Concerns/` — one trait per responsibility
- Use `$provider->providerCredentials()` for API keys, `$provider->additionalConfiguration()` for config
- Use `$options?->providerOptions($provider->driver())` for provider-specific options
- Add the appropriate provider interface to `BedrockProvider` (e.g., `ImageProvider`, `EmbeddingProvider`)
- Add the gateway method to `BedrockGateway` or create a separate gateway if the feature is complex
- Write Pest tests in `tests/Ai/` using `Http::fake()`
- Write Pest tests in `tests/Feature/` using the Laravel AI SDK's `fake()` function
- Update `README.md` with usage examples for any new feature
- Run `composer run test` and `composer run lint` before finishing
- PSR-12 style, `declare(strict_types=1)` in all PHP files

### Key Architecture

```
src/Ai/
├── BedrockGateway.php        # Implements TextGateway (and other gateway contracts)
├── BedrockProvider.php       # Extends Provider, implements TextProvider (and others)
└── Concerns/                 # One trait per responsibility
    ├── CreatesBedrockClient.php
    ├── BuildsTextRequests.php
    ├── MapsMessages.php
    ├── ParsesTextResponses.php
    └── HandlesTextStreaming.php
```

The driver is registered in `BedrockServiceProvider.php` via `Ai::extend('bedrock', ...)`.

Config comes from `config/ai.php`:
```php
'bedrock' => [
    'driver' => 'bedrock',
    'key' => env('AWS_BEDROCK_API_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
],
```

## Create a Pull Request

After implementing a feature, use the `create-pull-request` safe output to open a draft PR targeting `main` with:
- A clear title describing the feature
- A body explaining what was implemented and how it works

If the feature required only investigation with no code changes, skip the PR.

## Record Progress

Add a comment to discussion #3 with your findings and/or implementation results:

```
## Run: [date]

### Investigation / Completed: [topic]

[What you researched or implemented. Be specific about API endpoints, model IDs, and compatibility findings.]

**PR:** [link or "investigation only, no PR"]

### Next up
[What should be investigated or implemented next, and why]
```

Always be specific in your notes. Future runs depend on this memory to avoid repeating work.
