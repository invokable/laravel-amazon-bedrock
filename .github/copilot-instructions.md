# Project Guidelines

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://github.com/laravel/ai).

## NEXT

Prismにバグがあったので作ったパッケージだったけどその後Laravel AI SDKが登場したのでAI SDKに特化したパッケージにすれば独自に作ってる機能を削除できるのでリニューアルする。

- Laravel AI SDK特化パッケージへの移行はひとまず完了。レガシーコードは全て削除済み。引き続きテキスト生成以外の機能も実装していく。
- Anthropic以外のモデルも使ってテキスト生成以外のImages、TTS、STT、Embeddings、Reranking、Filesも可能なら対応する。
- Bedrock APIキー以外の認証方法にも対応する。Bedrockしか使えない制限があるのはエンプラなのでAPIキーのみだと使いにくいはず。

GitHub Agentic Workflowsで少しずつ実行。

## Technology Stack

- **Language**: PHP 8.4+
- **Framework**: Laravel 12.x+
- **Testing**: Pest PHP 4.x
- **Code Quality**: Laravel Pint (PSR-12)

## Command
- `composer run test` - Run pest tests.
- `composer run lint` - Run pint code formatter.

## Development Guidelines

- cache_control can only be used up to 4 blocks, so only system prompts are supported.
  - Error message `A maximum of 4 blocks with cache_control may be provided.` 
