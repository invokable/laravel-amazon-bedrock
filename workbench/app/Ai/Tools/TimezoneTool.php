<?php

declare(strict_types=1);

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class TimezoneTool implements Tool
{
    public function description(): string
    {
        return 'Get the current time for a given timezone.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'timezone' => $schema->string()->description('A valid PHP timezone identifier, e.g. Asia/Tokyo.'),
        ];
    }

    public function handle(Request $request): string
    {
        $tz = $request->string('timezone', 'UTC')->value();

        return now()->setTimezone($tz)->toDateTimeString();
    }
}
