<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\BusinessModules\Features\AIAssistant\Actions\Domains\AssistantDomainTool;

class AIToolRegistry
{
    private const TOOL_ALIASES = [
        'update_task_status' => 'update_schedule_task_status',
    ];

    /**
     * @var AIToolInterface[]
     */
    protected array $tools = [];

    /**
     * Register a new AI Tool into the registry.
     */
    public function registerTool(AIToolInterface $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Retrieve all registered tools.
     *
     * @return AIToolInterface[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * Attempt to retrieve a specific tool by its exact name.
     */
    public function getTool(string $name): ?AIToolInterface
    {
        $name = self::TOOL_ALIASES[$name] ?? $name;

        return $this->tools[$name] ?? null;
    }

    /**
     * Format all registered tools into the standard JSON Schema array
     * expected by OpenAI-compatible providers for Function Calling.
     */
    public function getToolsDefinitions(?array $allowedToolNames = null): array
    {
        $definitions = [];
        $allowed = null;

        if (is_array($allowedToolNames)) {
            $allowed = array_fill_keys(array_values(array_filter(
                $allowedToolNames,
                static fn (mixed $toolName): bool => is_string($toolName) && $toolName !== ''
            )), true);
        }

        foreach ($this->tools as $tool) {
            if (is_array($allowed) && ! isset($allowed[$tool->getName()])) {
                continue;
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $this->serializedSchema($tool),
                    'strict' => str_starts_with($tool->getName(), 'assistant_domain_'),
                ],
            ];
        }

        return $definitions;
    }

    private function serializedSchema(AIToolInterface $tool): array
    {
        $schema = $tool->getParametersSchema();
        if (! $tool instanceof AssistantDomainTool) {
            return $schema;
        }
        unset($schema['properties']['domain']['enum']);
        $schema['properties']['domain']['maxLength'] = 128;
        $schema['properties']['domain']['pattern'] = '^[a-zA-Z][a-zA-Z0-9_]*$';
        $schema['properties']['domain']['description'] = 'domain каталога. Права проверяет сервер.';
        unset($schema['properties']['entity_type']['enum']);
        $schema['properties']['entity_type']['maxLength'] = 128;
        $schema['properties']['entity_type']['pattern'] = '^[a-zA-Z][a-zA-Z0-9_]*$';
        $schema['properties']['entity_type']['description'] = 'Тип domain из каталога или assistant_domain_discover_capabilities.';
        if (isset($schema['properties']['fields']['items'])) {
            unset($schema['properties']['fields']['items']['enum']);
            $schema['properties']['fields']['items']['maxLength'] = 128;
            $schema['properties']['fields']['items']['pattern'] = '^[a-zA-Z][a-zA-Z0-9_]*$';
            $schema['properties']['fields']['description'] = 'Разрешённые поля каталога; null — доступные по умолчанию.';
        }
        return $schema;
    }
}
