<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

final class AssistantCapabilityCatalog
{
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
        return [
            [
                'id' => 'report.project_timelines',
                'domain' => 'reports',
                'capability' => 'schedules',
                'label' => 'Отчет по графику работ',
                'tool_name' => 'generate_project_timelines_report',
                'required_slots' => [
                    ['name' => 'period', 'type' => 'period', 'question' => 'За какой период сформировать отчет?'],
                ],
                'optional_slots' => [
                    ['name' => 'project_id', 'type' => 'project'],
                ],
                'read_permissions' => ['reports.view', 'schedule-management.view', 'admin.reports.view'],
                'artifact' => ['type' => 'pdf'],
                'intent_examples' => [
                    'сделай отчет по графику работ',
                    'сформируй отчет по срокам',
                    'покажи отчет по таймлайну проекта',
                    'отчет по отставанию от графика',
                ],
                'match_terms' => ['график работ', 'графику работ', 'сроки', 'таймлайн', 'отставание', 'этапы'],
            ],
            [
                'id' => 'report.material_movements',
                'domain' => 'reports',
                'capability' => 'warehouse',
                'label' => 'Отчет по движению материалов',
                'tool_name' => 'generate_material_movements_report',
                'required_slots' => [
                    ['name' => 'period', 'type' => 'period', 'question' => 'За какой период собрать движение материалов?'],
                ],
                'optional_slots' => [
                    ['name' => 'project_id', 'type' => 'project'],
                ],
                'read_permissions' => ['reports.view', 'admin.reports.view'],
                'artifact' => ['type' => 'excel'],
                'intent_examples' => [
                    'сделай отчет по расходу материалов',
                    'покажи движение материалов за месяц',
                    'сформируй отчет по материалам',
                ],
                'match_terms' => ['движение материалов', 'расход материалов', 'материалы', 'склад'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(string $id): ?array
    {
        foreach ($this->all() as $task) {
            if ($task['id'] === $id) {
                return $task;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    public function match(string $message, array $context): ?array
    {
        $normalized = mb_strtolower($message);

        foreach ($this->all() as $task) {
            foreach ($task['match_terms'] as $term) {
                if (str_contains($normalized, mb_strtolower($term))) {
                    return $task;
                }
            }
        }

        if (($context['source_module'] ?? null) === 'reports' && str_contains($normalized, 'отчет')) {
            return $this->findById('report.project_timelines');
        }

        return null;
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
