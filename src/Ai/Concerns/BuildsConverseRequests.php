<?php

declare(strict_types=1);

namespace Revolution\Amazon\Bedrock\Ai\Concerns;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Providers\Provider;

trait BuildsConverseRequests
{
    /**
     * Build a Converse API request body.
     */
    protected function buildConverseRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools = [],
        ?array $schema = null,
        ?TextGenerationOptions $options = null,
    ): array {
        $config = $provider->additionalConfiguration();
        $providerOptions = $options?->providerOptions($provider->driver()) ?? [];

        $body = [
            'messages' => $this->mapConverseMessages($messages),
        ];

        if (filled($instructions)) {
            $body['system'] = $this->buildConverseSystemPrompt($instructions);
        }

        $inferenceConfig = [];

        $maxTokens = $options?->maxTokens ?? (int) ($config['max_tokens'] ?? 8096);
        $inferenceConfig['maxTokens'] = $maxTokens;

        if ($options?->temperature !== null) {
            $inferenceConfig['temperature'] = $options->temperature;
        }

        if (filled($inferenceConfig)) {
            $body['inferenceConfig'] = $inferenceConfig;
        }

        $mappedTools = filled($tools) ? $this->mapConverseTools($tools) : [];

        if (filled($schema)) {
            $mappedTools[] = $this->buildConverseStructuredOutputTool($schema);
        }

        if (filled($mappedTools)) {
            $toolConfig = ['tools' => $mappedTools];
            $toolConfig['toolChoice'] = $this->resolveConverseToolChoice($schema, $tools, $providerOptions);
            $body['toolConfig'] = $toolConfig;
        }

        $additionalFields = $providerOptions['additionalModelRequestFields'] ?? [];

        unset($providerOptions['anthropic_version']);
        unset($providerOptions['additionalModelRequestFields']);

        $additionalFields = $this->mergeProviderOptionsIntoAdditionalFields($additionalFields, $providerOptions);

        if (filled($additionalFields)) {
            $body['additionalModelRequestFields'] = $additionalFields;
        }

        return $body;
    }

    /**
     * Build a Converse system prompt with a prompt cache checkpoint.
     *
     * The cachePoint block tells Bedrock to cache the preceding static
     * system prompt prefix for reuse in subsequent Converse API calls.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function buildConverseSystemPrompt(string $instructions): array
    {
        return [
            ['text' => $instructions],
            ['cachePoint' => ['type' => 'default']],
        ];
    }

    /**
     * Pass remaining provider-specific options through Converse additionalModelRequestFields.
     */
    protected function mergeProviderOptionsIntoAdditionalFields(array $additionalFields, array $providerOptions): array
    {
        return array_merge($additionalFields, $providerOptions);
    }

    /**
     * Map AI SDK messages to Converse API format.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function mapConverseMessages(array $messages): array
    {
        $mapped = [];

        foreach ($messages as $message) {
            $message = Message::tryFrom($message);

            match ($message->role) {
                MessageRole::User => $this->mapConverseUserMessage($message, $mapped),
                MessageRole::Assistant => $this->mapConverseAssistantMessage($message, $mapped),
                MessageRole::ToolResult => $this->mapConverseToolResultMessage($message, $mapped),
            };
        }

        return $mapped;
    }

    protected function mapConverseUserMessage(UserMessage|Message $message, array &$mapped): void
    {
        $content = [
            ['text' => $message->content],
        ];

        if ($message instanceof UserMessage && $message->attachments->isNotEmpty()) {
            // Bedrock Converse examples place media and document blocks before the prompt text.
            $content = array_merge($this->mapConverseAttachments($message->attachments), $content);
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    protected function mapConverseAssistantMessage(
        AssistantMessage|Message $message,
        array &$mapped,
    ): void {
        $content = [];
        $hasToolCalls = $message instanceof AssistantMessage && $message->toolCalls->isNotEmpty();

        if (filled($message->content)) {
            $content[] = ['text' => $message->content];
        }

        if ($hasToolCalls) {
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'toolUse' => [
                        'toolUseId' => $toolCall->id,
                        'name' => $toolCall->name,
                        'input' => (object) $toolCall->arguments,
                    ],
                ];
            }
        }

        if (filled($content)) {
            $mapped[] = [
                'role' => 'assistant',
                'content' => $content,
            ];
        }
    }

    protected function mapConverseToolResultMessage(
        ToolResultMessage|Message $message,
        array &$mapped,
    ): void {
        if (! $message instanceof ToolResultMessage) {
            return;
        }

        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => $this->serializeToolResultOutput($toolResult->result)],
                    ],
                ],
            ];
        }

        $mapped[] = [
            'role' => 'user',
            'content' => $content,
        ];
    }

    /**
     * Map AI SDK tools to Converse API tool definitions.
     *
     * @param  array<Tool>  $tools
     * @return array<int, array<string, mixed>>
     */
    protected function mapConverseTools(array $tools): array
    {
        $mapped = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $mapped[] = $this->mapConverseTool($tool);
            }
        }

        return $mapped;
    }

    /**
     * Map a single Tool instance to a Converse API toolSpec.
     */
    protected function mapConverseTool(Tool $tool): array
    {
        $schema = $tool->schema(new JsonSchemaTypeFactory);

        $inputSchema = ['type' => 'object', 'properties' => (object) []];

        if (filled($schema)) {
            $schemaArray = (new ObjectSchema($schema))->toSchema();

            $inputSchema['properties'] = (object) ($schemaArray['properties'] ?? []);
            $inputSchema['required'] = $schemaArray['required'] ?? [];
        }

        return [
            'toolSpec' => [
                'name' => class_basename($tool),
                'description' => (string) $tool->description(),
                'inputSchema' => [
                    'json' => $inputSchema,
                ],
            ],
        ];
    }

    /**
     * Build the synthetic tool for structured output in Converse API format.
     */
    protected function buildConverseStructuredOutputTool(array $schema): array
    {
        $schemaArray = (new ObjectSchema($schema))->toSchema();

        return [
            'toolSpec' => [
                'name' => 'output_structured_data',
                'description' => 'Output the structured data matching the required schema.',
                'inputSchema' => [
                    'json' => [
                        'type' => 'object',
                        'properties' => (object) ($schemaArray['properties'] ?? []),
                        'required' => $schemaArray['required'] ?? [],
                    ],
                ],
            ],
        ];
    }

    /**
     * Determine the toolChoice strategy for the Converse API.
     */
    protected function resolveConverseToolChoice(?array $schema, array $tools, array $providerOptions): array
    {
        if (! filled($schema)) {
            return ['auto' => new \stdClass];
        }

        return filled($tools)
            ? ['any' => new \stdClass]
            : ['tool' => ['name' => 'output_structured_data']];
    }

    protected function serializeToolResultOutput(mixed $output): string
    {
        return match (true) {
            is_string($output) => $output,
            is_array($output) => json_encode($output),
            default => strval($output),
        };
    }
}
