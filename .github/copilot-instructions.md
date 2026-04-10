# Project Guidelines

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://github.com/laravel/ai).

## NEXT

Prismにバグがあったので作ったパッケージだったけどその後Laravel AI SDKが登場したのでAI SDKに特化したパッケージにすれば独自に作ってる機能を削除できるのでリニューアルする。

- [x] Laravel AI SDK特化パッケージへの移行はひとまず完了。レガシーコードは全て削除済み。引き続きテキスト生成以外の機能も実装していく。
- Anthropic以外のモデルも使ってテキスト生成以外のImages、TTS、STT、Embeddings、Reranking、Filesも可能なら対応する。難しい機能は対応せずREADMEの機能表に非対応と記載する。Laravel AI SDK公式でも全部に対応しているプロバイダーはない。
- [x] Bedrock APIキー以外の認証方法にも対応する。Bedrockしか使えない制限があるのはエンプラなのでAPIキーのみだと使いにくいはず。
- テキスト生成もAnthropic以外のモデルに対応する。
- エンドポイントは`bedrock-runtime`とOpenAI互換API用の`bedrock-mantle`がある。
- 機能追加のタスクがなくなったら既存コードのリファクタリングやテスト追加やLaravel AI SDKのアップデート対応を行う。Laravel AI SDKはまだv0.x、composerはv1.0前では+0.1でもメジャーバージョンアップ扱いなのでまだまだ破壊的変更が入る可能性がある。

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
