<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Actions\Domains\AssistantDomainTool;
use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use Closure;
use UnexpectedValueException;

class AIToolRegistry
{
    private const TOOL_ALIASES = [
        'update_task_status' => 'update_schedule_task_status',
    ];

    /**
     * @var AIToolInterface[]
     */
    protected array $tools = [];

    private array $factories = [];

    private array $toolNames = [];

    /**
     * Register a new AI Tool into the registry.
     */
    public function registerTool(AIToolInterface $tool): void
    {
        $name = $tool->getName();
        $this->rememberToolName($name);
        unset($this->factories[$name]);
        $this->tools[$name] = $tool;
    }

    public function registerFactory(string $name, callable $factory): void
    {
        $this->rememberToolName($name);
        unset($this->tools[$name]);
        $this->factories[$name] = Closure::fromCallable($factory);
    }

    public function getToolNames(): array
    {
        return $this->toolNames;
    }

    /**
     * Retrieve all registered tools.
     *
     * @return AIToolInterface[]
     */
    public function getTools(): array
    {
        $tools = [];
        foreach ($this->toolNames as $name) {
            $tool = $this->getTool($name);
            if ($tool !== null) {
                $tools[$name] = $tool;
            }
        }

        return $tools;
    }

    /**
     * Attempt to retrieve a specific tool by its exact name.
     */
    public function getTool(string $name): ?AIToolInterface
    {
        $name = self::TOOL_ALIASES[$name] ?? $name;

        if (isset($this->tools[$name])) {
            return $this->tools[$name];
        }

        $factory = $this->factories[$name] ?? null;
        if ($factory === null) {
            return null;
        }

        $tool = $factory();
        if (! $tool instanceof AIToolInterface || $tool->getName() !== $name) {
            throw new UnexpectedValueException('assistant_tool_factory_name_mismatch');
        }

        return $this->tools[$name] = $tool;
    }

    /**
     * Format all registered tools into the standard JSON Schema array
     * expected by OpenAI-compatible providers for Function Calling.
     */
    public function getToolsDefinitions(?array $allowedToolNames = null, bool $nativeResponses = false): array
    {
        $definitions = [];
        $allowed = null;

        if (is_array($allowedToolNames)) {
            $allowed = array_fill_keys(array_values(array_filter(
                $allowedToolNames,
                static fn (mixed $toolName): bool => is_string($toolName) && $toolName !== ''
            )), true);
        }

        foreach ($this->toolNames as $name) {
            if (is_array($allowed) && ! isset($allowed[$name])) {
                continue;
            }

            $tool = $this->getTool($name);
            if ($tool === null) {
                continue;
            }

            $function = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'parameters' => $this->serializedSchema($tool),
                'strict' => str_starts_with($tool->getName(), 'assistant_domain_'),
            ];
            $definitions[] = $nativeResponses
                ? ['type' => 'function', ...$function]
                : ['type' => 'function', 'function' => $function];
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

    private function rememberToolName(string $name): void
    {
        if (! in_array($name, $this->toolNames, true)) {
            $this->toolNames[] = $name;
        }
    }
}
