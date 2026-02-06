# Project Guidelines

## Overview

A lightweight Laravel package to easily interact with Amazon Bedrock, specifically for generating text.

- **Features**: Text Generation only.
- **Supported Model**: Anthropic Claude Haiku/Sonnet/Opus 4 and later.(Default: Sonnet 4.5)
- **Authentication**: Bedrock API Key only.
- **Cache Control**: Always enabled ephemeral cache at system prompt.
- **Minimal Dependencies**: No extra dependencies except Laravel framework.

## Technology Stack

- **Language**: PHP 8.4+
- **Framework**: Laravel 12.x+
- **Testing**: Pest PHP 4.x
- **Code Quality**: Laravel Pint (PSR-12)

## Command
- `composer run test` - Run pest tests.
- `composer run lint` - Run pint code formatter.

## Development Guidelines

- Keep Prism compatibility in mind when making changes.
- cache_control can only be used up to 4 blocks, so only system prompts are supported.
  - Error message `A maximum of 4 blocks with cache_control may be provided.` 

## Testing

- Don't write test-only code inside production code. Use service containers to swap it in.

## Laravel AI SDK Integration

- Experimental implementation.
- Support only text generation. No other features are supported, including text streams.
