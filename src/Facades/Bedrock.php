<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Facades;

use Illuminate\Support\Facades\Facade;
use Revolution\Amazon\Bedrock\BedrockClient;
use Revolution\Amazon\Bedrock\Testing\BedrockFake;
use Revolution\Amazon\Bedrock\Text\PendingRequest;
use Revolution\Amazon\Bedrock\Text\Response;

/**
 * @method static PendingRequest text()
 */
class Bedrock extends Facade
{
    /**
     * @param  array<int, Response>  $responses
     */
    public static function fake(array $responses = []): BedrockFake
    {
        $fake = new BedrockFake($responses);

        static::swap(new BedrockClient($fake));

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return BedrockClient::class;
    }
}
