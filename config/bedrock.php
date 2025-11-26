<?php

use Illuminate\Support\Env;

return [
    'anthropic_version' => Env::get('AWS_BEDROCK_ANTHROPIC_VERSION', 'bedrock-2023-05-31'),
    'region' => Env::get('AWS_DEFAULT_REGION', 'us-east-1'),
    'api_key' => Env::get('AWS_BEDROCK_API_KEY'),
    'model' => Env::get('AWS_BEDROCK_MODEL', 'global.anthropic.claude-sonnet-4-5-20250929-v1:0'),
    'max_tokens' => Env::get('AWS_BEDROCK_MAX_TOKENS', 2048),
    'timeout' => Env::get('AWS_BEDROCK_TIMEOUT', 30),
];
