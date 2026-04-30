# Project Guidelines

## Overview

An Amazon Bedrock driver for the [Laravel AI SDK](https://github.com/laravel/ai).

## NEXT

Prismにバグがあったので作ったパッケージだったけどその後Laravel AI SDKが登場したのでAI SDKに特化したパッケージにすれば独自に作ってる機能を削除できるのでリニューアルする。

- [x] Laravel AI SDK特化パッケージへの移行はひとまず完了。レガシーコードは全て削除済み。引き続きテキスト生成以外の機能も実装していく。
- [x] Anthropic以外のモデルも使ってテキスト生成以外のImages、TTS、STT、Embeddings、Reranking、Filesも可能なら対応する。難しい機能は対応せずREADMEの機能表に非対応と記載する。Laravel AI SDK公式でも全部に対応しているプロバイダーはない。
- [x] Bedrock APIキー以外の認証方法にも対応する。Bedrockしか使えない制限があるのはエンプラなのでAPIキーのみだと使いにくいはず。
- [x] テキスト生成もAnthropic以外のモデルに対応する。APIフォーマットが違うので調査が必要。Bedrockの特徴はオープンウェイトモデルも色々選べることなので差別化要因。
- エンドポイントは`bedrock-runtime`とOpenAI互換API用の`bedrock-mantle`がある。
- [x] Bedrockでも最近[StructuredOutput](https://docs.aws.amazon.com/bedrock/latest/userguide/structured-output.html)に対応したので追加できるはず。→AI SDKも使っているツールを使う方法で実装。
- `workbench/routes/console.php`に開発環境でBedrock APIキーをセットして実際に動かすArtisanコマンドを作る。APIキーで使えないreranking以外は成功したのでここまで正しく実装できている。JsonSchemaの使い方がREADMEの記載から間違っていたので今後も注意。
- [x] `HandlesFailoverErrors`トレイトへの対応が必要かつ可能なら追加。AI SDKのfailover機能用。
- [x] 主な機能追加は完了したけど初期からdiscussionに書いていた **Cohere Embed batch support** が未完了かも。
- [x] Imageで使っている`amazon.nova-canvas-v1:0`は廃止が決定しているので別のモデルに変更。とはいえ`amazon.titan-image-generator-v2:0`も廃止なので代わりのモデルは [Stability AI](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-stability-diffusion.html) のいくつかしかないかも。 [Model lifecycle](https://docs.aws.amazon.com/bedrock/latest/userguide/model-lifecycle.html)
- [x] Stable Image Coreでの画像生成は成功。`aspect_ratio`でsize指定もできたので追加済み。Stability AI Image Servicesへの対応は`$attachments`があるのでプロンプトと画像を渡して画像編集も可能かもしれないけど要調査。https://docs.aws.amazon.com/bedrock/latest/userguide/stable-image-services.html
- [x] 実装できそうな機能はすべて実装完了。
- [x] 会話履歴はすでに対応できてそうなので念のためREADMEの構造化の上に説明を追加。
- [x] 公式がBedrockの一部機能に対応したのでKEYを`amazon-bedrock`に変更。全機能には対応してないのでこのパッケージは残す。Bedrock API keyで使える機能しか対応してないようなのでプルリクで貢献しようとしてもおそらくマージされない。別パッケージで継続。
- [x] 公式のパッチバージョンの変化でテストが失敗しているので修正が必要。
- [x] Converse APIはテキスト生成時にファイルの添付に対応している。`->prompt('', attachments: [])`からUserMessageのattachmentsに渡されている。今のコードは公式のAnthropicの実装を参考にしているので`mapConverseUserMessage()`を公式の`mapUserMessage()`のようにすればいいはず。ファイル名などBedrock特有の制限がある。TranscriptionsのAudioBlockと似たパターン。`audio` `image` `document` `video`に対応している。READMEで非対応にしていたFilesはこの添付機能が一般的な用途でサーバーにアップロードは一部プロバイダーのみの機能。
- [x] InvokeModelよりConverse APIの方が新しいのでAnthropicもConverse APIに変更して統一。
- [x] Transcriptionsは `workbench/routes/console.php` で試したら動かない。AudioBlockに対応してるモデルがもうない。Amazon Transcribeを使うのがもう主流。一旦非対応に戻す。AudioBlockを使う方法は削除、いつか復旧するときはこのPRから取り出す https://github.com/invokable/laravel-amazon-bedrock/pull/32
- [ ] 公式でTranscriptionsに対応してるのはOpenAI、ElevenLabs、Mistral。BedrockでOpenAIのモデル(gpt-oss以外も)が使えるようになるらしいニュースがあったので様子を見る。
- 機能追加のタスクがなくなったら既存コードのリファクタリングやテスト追加やLaravel AI SDKのアップデート対応を行う。Laravel AI SDKはまだv0.x、composerはv1.0前では+0.1でもメジャーバージョンアップ扱いなのでまだまだ破壊的変更が入る可能性がある。

GitHub Agentic Workflowsで少しずつ実行。
実装フェーズは完了したので毎日実行から週一実行に変更。Laravelチームは火曜日に定期リリースしているので水曜日に実行すれば十分。AI SDKは開発中なのでまだ定期リリースとは限らないけど対応が必要な時はAWを手動で実行する。

## Technology Stack

- **Language**: PHP 8.3+
- **Framework**: Laravel 12.x+
- **Testing**: Pest PHP 4.x
- **Code Quality**: Laravel Pint (PSR-12)

## Command
- `composer run test` - Run pest tests.
- `composer run lint` - Run pint code formatter.

## Versioning

Laravel AI SDKのメジャーバージョンとこのパッケージのバージョンを合わせる。
v0.6.xの間はこのパッケージもv0.6.x。
人間がタグを付けてリリース作業する部分だけど`composer.json`を変更する時はLaravel AI SDKに合わせる。

## Development Guidelines

- cache_control can only be used up to 4 blocks, so only system prompts are supported.
  - Error message `A maximum of 4 blocks with cache_control may be provided.` 
