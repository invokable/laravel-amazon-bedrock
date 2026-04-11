<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;

trait MapsTools
{
    /**
     * Map the given tools to Anthropic/Bedrock tool definitions.
     *
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $mapped[] = $this->mapTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a single Tool instance to an Anthropic/Bedrock tool definition.
     */
    protected function mapTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $inputSchema = ['type' => 'object', 'properties' => (object) []];

        if (filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
            $inputSchema['required'] = $schemaArray['required'] ?? [];
        }

        return [
            'name' => class_basename($tool),
            'description' => (string) $tool->description(),
            'input_schema' => $inputSchema,
        ];
    }
}
