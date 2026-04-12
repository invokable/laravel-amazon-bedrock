<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Env;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Revolution\Amazon\Bedrock\Bedrock;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // 開発環境でのテスト用なのでここでenvを読み込んでも問題ない
        Config::set('ai.providers.'.Bedrock::KEY, [
            'driver' => Bedrock::KEY,
            'key' => Env::get('AWS_BEDROCK_API_KEY', ''),
            'region' => Env::get('AWS_DEFAULT_REGION', 'us-east-1'),
        ]);

        Config::set('ai.default', Bedrock::KEY);
    }
}
