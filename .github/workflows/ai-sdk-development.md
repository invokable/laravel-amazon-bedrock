---
name: Laravel AI SDK Development
description: Incrementally implements Laravel AI SDK integration for Amazon Bedrock. Reads discussions/3 as memory, implements one feature at a time on the next branch, and records progress back to the discussion.

on:
  schedule:
    - cron: '0 2 * * *'
  workflow_dispatch:

permissions:
  contents: read
  discussions: read
  issues: read
  pull-requests: read

checkout:
  ref: next

tools:
  github:
    toolsets: [default, discussions]
  edit: {}
  bash: true
  web-fetch: {}

network:
  allowed:
    - github.com
    - raw.githubusercontent.com
    - api.github.com

safe-outputs:
  add-comment:
    target: "*"
    discussions: true
    issues: false
    pull-requests: false
    max: 1
  create-pull-request:
    base-branch: next
    title-prefix: "[ai-sdk] "
    labels: [ai-sdk]
    draft: true
    if-no-changes: warn
---

# Laravel AI SDK Development

You are a PHP developer incrementally building Laravel AI SDK integration for the `invokable/laravel-amazon-bedrock` package.

## Your Mission

Each run, you pick **one** clearly scoped task, implement it on the `next` branch, open a draft PR, and record your progress as a comment on discussion #3.

## Step 1: Read Your Memory

Read discussion #3 in the `invokable/laravel-amazon-bedrock` repository to understand:
- What has already been implemented
- What is currently in progress
- What the next priority task should be

If there are no previous comments yet, start from the beginning of the implementation plan below.

## Step 2: Read the Codebase

Examine the current state of the `next` branch:
- `src/Ai/BedrockProvider.php`
- `src/Ai/BedrockGateway.php`
- `src/BedrockServiceProvider.php`
- `composer.json`
- `README.md`
- `resources/boost/guidelines/core.blade.php` (if it exists)

## Step 3: Read the Laravel AI SDK Documentation

Fetch the primary sources for the Laravel AI SDK:
- `https://raw.githubusercontent.com/laravel/docs/13.x/ai-sdk.md` — official documentation
- `https://raw.githubusercontent.com/laravel/ai/refs/heads/main/README.md` — package README
- Browse `https://github.com/laravel/ai` to understand the provider/gateway contract — check `src/Contracts/` directory and existing provider implementations

Key files to check in `laravel/ai`:
- `https://raw.githubusercontent.com/laravel/ai/refs/heads/main/src/Contracts/Gateway/TextGateway.php`
- `https://raw.githubusercontent.com/laravel/ai/refs/heads/main/src/Contracts/Providers/TextProvider.php`
- `https://raw.githubusercontent.com/laravel/ai/refs/heads/main/src/Providers/Provider.php`

## Step 4: Determine the Next Task

Based on the discussion history and codebase state, choose **exactly one** task from this ordered list that hasn't been completed yet:

1. **Bootstrap**: Set up `BedrockProvider` registration in `BedrockServiceProvider` so `bedrock-anthropic` driver is registered with the Laravel AI SDK when `laravel/ai` is installed. Update `composer.json` to add `laravel/ai` as a suggested dependency.

2. **Text Generation**: Implement `generateText()` in `BedrockGateway` using the existing `Bedrock` facade (not Prism). Remove any Prism imports/dependencies from `BedrockGateway`. Use `Revolution\Amazon\Bedrock\Facades\Bedrock` directly.

3. **Streaming**: Implement `streamText()` in `BedrockGateway` using `Bedrock::text()->asStream()`. Map Bedrock stream events to Laravel AI SDK stream events (`StreamStart`, `TextStart`, `TextDelta`, `TextEnd`).

4. **Structured Output**: Implement `generateText()` structured output support (JSON schema). Pass schema to Bedrock's tool_use or document the limitation if Bedrock doesn't support it the same way.

5. **Configuration**: Update `config/bedrock.php` (if it exists in workbench) to include model configuration for `text.default`, `text.cheapest`, `text.smartest`. Check what models are available.

6. **Remove Facade Dependency from Gateway**: Ensure `BedrockGateway` has no remaining dependency on `Prism\Prism` or `Laravel\Ai\Gateway\Prism\PrismException`. Use native Bedrock error handling instead.

7. **Update README**: Rewrite `README.md` to document the Laravel AI SDK integration as the primary usage, with setup instructions for `config/ai.php`, example code for `agent()` helper, text generation, and streaming. Remove or archive old Bedrock facade documentation.

8. **Update Boost Guidelines**: Update `resources/boost/guidelines/core.blade.php` to reflect the new AI SDK-focused architecture if the file exists.

9. **Tests**: Write Pest tests for `BedrockGateway` covering `generateText()` and `streamText()` using fakes/mocks. Tests go in `tests/Ai/`.

If all tasks are done, post a completion summary to discussion #3 and do not create a PR.

## Step 5: Implement the Task

Make precise, surgical changes:
- Only modify files relevant to the chosen task
- Follow PSR-12 coding style (enforced by Laravel Pint)
- Use `declare(strict_types=1)` in all PHP files
- Add docblocks only where truly needed for clarity
- Do NOT add `laravel/ai` as a hard dependency — it must remain optional (suggest only)
- The `BedrockGateway` must use `Revolution\Amazon\Bedrock\Facades\Bedrock` for API calls

After making changes, run:
```bash
composer install --no-interaction 2>&1 | tail -5
```
to verify the package installs cleanly.

## Step 6: Create a Pull Request

Use the `create-pull-request` safe output to open a draft PR targeting the `next` branch with:
- A clear title describing the task (e.g., "Implement text generation in BedrockGateway")
- A body explaining what was changed and why

## Step 7: Record Progress

Add a comment to discussion #3 with:
- What task was completed in this run
- Summary of changes made
- Link to the PR (if created)
- What the next task should be (from the ordered list above)

Format the comment as:
```
## Run: [date]

### Completed: [task name]

[Brief description of what was done]

**PR:** [link or "no PR needed"]

### Next up: [next task name]
```
