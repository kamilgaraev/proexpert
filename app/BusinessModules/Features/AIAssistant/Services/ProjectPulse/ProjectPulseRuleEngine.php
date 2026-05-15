<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseRecommendation;
use Illuminate\Support\Collection;

class ProjectPulseRuleEngine
{
    public function status(Collection $facts): string
    {
        if ($facts->where('priority', 'critical')->isNotEmpty()) {
            return 'critical';
        }

        if ($facts->where('priority', 'warning')->isNotEmpty()) {
            return 'warning';
        }

        return 'good';
    }

    public function summary(Collection $facts): array
    {
        $critical = $facts->where('priority', 'critical')->count();
        $warning = $facts->where('priority', 'warning')->count();

        if ($critical > 0) {
            return [
                'title' => 'Есть критичные вопросы',
                'text' => 'Обнаружены события, которые требуют управленческого решения сегодня.',
            ];
        }

        if ($warning > 0) {
            return [
                'title' => 'Есть вопросы для контроля',
                'text' => 'По проектному контуру есть события, которые стоит проверить в рабочем порядке.',
            ];
        }

        return [
            'title' => 'Ситуация стабильная',
            'text' => 'Критичных событий за выбранный период не найдено.',
        ];
    }

    public function categories(Collection $facts): array
    {
        $labels = $this->configArray('ai-assistant.project_pulse.categories');

        return $facts
            ->groupBy(fn (ProjectPulseFact $fact) => $fact->category)
            ->map(fn (Collection $categoryFacts, string $category) => [
                'key' => $category,
                'label' => (string) ($labels[$category] ?? $category),
                'status' => $this->status($categoryFacts),
                'critical_count' => $categoryFacts->where('priority', 'critical')->count(),
                'warning_count' => $categoryFacts->where('priority', 'warning')->count(),
                'info_count' => $categoryFacts->where('priority', 'info')->count(),
                'amount' => $categoryFacts->sum(fn (ProjectPulseFact $fact) => (float) ($fact->amount ?? 0)),
            ])
            ->sortBy(function (array $category) use ($labels): int {
                $position = array_search($category['key'], array_keys($labels), true);

                return $position === false ? 999 : (int) $position;
            })
            ->values()
            ->all();
    }

    public function groups(Collection $facts): array
    {
        $definitions = [
            'requires_action' => ['label' => 'Требует реакции', 'filter' => fn (ProjectPulseFact $fact) => $fact->nextAction !== null || $fact->primaryAction !== null],
            'critical' => ['label' => 'Критичные события', 'filter' => fn (ProjectPulseFact $fact) => $fact->priority === 'critical'],
            'today' => ['label' => 'События за период', 'filter' => fn (ProjectPulseFact $fact) => $fact->occurredAt !== null],
            'procurement' => ['label' => 'Закупки', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'procurement'],
            'warehouse' => ['label' => 'Склад', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'warehouse'],
            'finance' => ['label' => 'Финансы', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'finance'],
            'schedule' => ['label' => 'График', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'schedule'],
            'quality' => ['label' => 'Качество', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'quality'],
            'documentation' => ['label' => 'ИД', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'documentation'],
            'safety' => ['label' => 'HSE', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'safety'],
            'machinery' => ['label' => 'Техника', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'machinery'],
            'labor' => ['label' => 'Выработка', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'labor'],
            'change' => ['label' => 'Изменения', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'change'],
            'handover' => ['label' => 'Сдача', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'handover'],
            'contracts' => ['label' => 'Договоры', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'contract'],
            'reports' => ['label' => 'Отчеты', 'filter' => fn (ProjectPulseFact $fact) => $fact->category === 'report'],
        ];

        return collect($definitions)
            ->map(function (array $definition, string $key) use ($facts): array {
                $items = $facts->filter($definition['filter'])->values();

                return [
                    'key' => $key,
                    'label' => $definition['label'],
                    'status' => $this->status($items),
                    'facts' => $items->map->toArray()->all(),
                ];
            })
            ->filter(fn (array $group) => count($group['facts']) > 0)
            ->values()
            ->all();
    }

    public function nextActions(Collection $facts): array
    {
        return $facts
            ->filter(fn (ProjectPulseFact $fact) => $fact->nextAction !== null || $fact->primaryAction !== null)
            ->sortBy(fn (ProjectPulseFact $fact) => ($this->priorityRank($fact->priority) * 100000) - (int) ($fact->ageDays ?? 0))
            ->take((int) $this->configValue('ai-assistant.project_pulse.limits.next_actions', 10))
            ->map(fn (ProjectPulseFact $fact) => [
                'id' => $fact->id,
                'priority' => $fact->priority,
                'category' => $fact->category,
                'title' => $fact->title,
                'text' => $fact->nextAction ?? $fact->text,
                'project_id' => $fact->projectId,
                'project_name' => $fact->projectName,
                'related_entity' => $fact->relatedEntity,
                'primary_action' => $fact->primaryAction,
                'deadline' => $fact->deadline,
                'age_days' => $fact->ageDays,
            ])
            ->values()
            ->all();
    }

    public function recommendations(Collection $facts): Collection
    {
        return $facts
            ->filter(fn (ProjectPulseFact $fact) => in_array($fact->priority, ['critical', 'warning'], true))
            ->take((int) $this->configValue('ai-assistant.project_pulse.limits.recommendations', 12))
            ->map(fn (ProjectPulseFact $fact) => new ProjectPulseRecommendation(
                id: 'rules:' . $fact->id,
                priority: $fact->priority === 'critical' ? 'high' : 'medium',
                title: $this->recommendationTitle($fact),
                action: $fact->nextAction ?? $this->recommendationAction($fact),
                reason: $fact->text,
                expectedEffect: 'Снижение риска срыва сроков, простоя или финансового отклонения.',
                projectId: $fact->projectId,
                route: $fact->primaryAction['route'] ?? $fact->relatedEntity['route'] ?? ($fact->projectId ? '/projects/' . $fact->projectId : null),
                source: 'rules',
            ))
            ->values();
    }

    public function riskGroups(Collection $facts): array
    {
        return $this->groups($facts);
    }

    public function urgentActions(Collection $facts): array
    {
        return $this->nextActions($facts);
    }

    public function activity(Collection $facts): array
    {
        return $facts
            ->whereNotNull('occurredAt')
            ->sortByDesc('occurredAt')
            ->take(20)
            ->map(fn (ProjectPulseFact $fact) => [
                'id' => $fact->id,
                'type' => $fact->type,
                'category' => $fact->category,
                'title' => $fact->title,
                'subtitle' => $fact->projectName,
                'occurred_at' => $fact->occurredAt,
                'route' => $fact->relatedEntity['route'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function priorityRank(string $priority): int
    {
        return match ($priority) {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        };
    }

    private function recommendationTitle(ProjectPulseFact $fact): string
    {
        return match ($fact->category) {
            'procurement' => 'Закрыть закупочный следующий шаг',
            'warehouse' => 'Проверить складское обеспечение',
            'finance' => 'Проверить финансовое действие',
            'contract' => 'Проверить договорной контур',
            'schedule' => 'Актуализировать график',
            'quality' => 'Закрыть риск по качеству',
            'documentation' => 'Довести исполнительную документацию',
            'safety' => 'Закрыть HSE-риск',
            'machinery' => 'Разобрать простой техники',
            'labor' => 'Проверить выработку бригады',
            'change' => 'Довести изменение до решения',
            'handover' => 'Разблокировать сдачу зоны',
            'people' => 'Назначить ответственного',
            default => 'Проверить событие по проекту',
        };
    }

    private function recommendationAction(ProjectPulseFact $fact): string
    {
        return $fact->nextAction ?? 'Сверить факт, ответственного и следующий шаг по связанному объекту.';
    }

    private function configArray(string $key): array
    {
        $value = $this->configValue($key, []);

        return is_array($value) ? $value : [];
    }

    private function configValue(string $key, mixed $default): mixed
    {
        try {
            return config($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
