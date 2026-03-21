<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;

class AIToolRegistry
{
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
        return $this->tools[$name] ?? null;
    }

    /**
     * Format all registered tools into the standard JSON Schema array 
     * expected by OpenAI and YandexGPT for Function Calling.
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
            if (is_array($allowed) && !isset($allowed[$tool->getName()])) {
                continue;
            }

            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParametersSchema(),
                ],
            ];
        }

        return $definitions;
    }
}
