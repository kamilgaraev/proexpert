<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportCatalog;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportIntentResolver;

final readonly class AssistantCapabilityCatalog
{
    public function __construct(
        private AssistantReportCatalog $reportCatalog = new AssistantReportCatalog,
        private AssistantReportIntentResolver $reportIntentResolver = new AssistantReportIntentResolver
    ) {}

    /**
     * @return array<int, array{
     *     id: string,
     *     domain: string,
     *     capability: string,
     *     label: string,
     *     tool_name: string,
     *     required_slots: array<int, array{name: string, type: string, question: string}>,
     *     optional_slots: array<int, array{name: string, type: string}>,
     *     read_permissions: string[],
     *     artifact: array{type: string},
     *     intent_examples: string[],
     *     match_terms: string[]
     * }>
     */
    public function all(): array
    {
        return $this->reportCatalog->agentTasks();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        $definition = $this->reportCatalog->findById($id);

        return $definition?->toAgentTask();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function match(string $message, array $context): ?array
    {
        $result = $this->reportIntentResolver->resolve($message, $context);

        return ($result['status'] ?? null) === 'matched'
            ? ($result['definition'] ?? null)?->toAgentTask()
            : null;
    }

    /**
     * @return string[]
     */
    public function requiredSlotNames(string $taskId): array
    {
        $task = $this->findById($taskId);

        return array_map(
            static fn (array $slot): string => (string) $slot['name'],
            is_array($task) ? ($task['required_slots'] ?? []) : []
        );
    }

    /**
     * @return string[]
     */
    public function optionalSlotNames(string $taskId): array
    {
        $task = $this->findById($taskId);

        return array_map(
            static fn (array $slot): string => (string) $slot['name'],
            is_array($task) ? ($task['optional_slots'] ?? []) : []
        );
    }
}
