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
                'text' => 'Система нашла события, которые стоит проверить в рабочем порядке.',
            ];
        }

        return [
            'title' => 'Ситуация стабильная',
            'text' => 'Критичных событий за выбранный период не найдено.',
        ];
    }

    public function recommendations(Collection $facts): Collection
    {
        return $facts
            ->filter(fn (ProjectPulseFact $fact) => in_array($fact->priority, ['critical', 'warning'], true))
            ->take(10)
            ->map(fn (ProjectPulseFact $fact) => new ProjectPulseRecommendation(
                id: 'rules:' . $fact->id,
                priority: $fact->priority === 'critical' ? 'high' : 'medium',
                title: $this->recommendationTitle($fact),
                action: $this->recommendationAction($fact),
                reason: $fact->text,
                expectedEffect: 'Снижение риска срыва сроков, простоя или финансового отклонения.',
                projectId: $fact->projectId,
                route: $fact->relatedEntity['route'] ?? ($fact->projectId ? '/projects/' . $fact->projectId : null),
                source: 'rules',
            ))
            ->values();
    }

    public function riskGroups(Collection $facts): array
    {
        return collect([
            'schedule' => 'Сроки',
            'site_request' => 'Заявки',
            'finance' => 'Финансы',
        ])->map(fn (string $title, string $key) => [
            'key' => $key,
            'title' => $title,
            'status' => $this->status($facts->filter(fn (ProjectPulseFact $fact) => $fact->type === $key || $fact->type === 'completed_work' && $key === 'finance')),
            'items' => $facts
                ->filter(fn (ProjectPulseFact $fact) => $fact->type === $key || $fact->type === 'completed_work' && $key === 'finance')
                ->whereIn('priority', ['critical', 'warning'])
                ->map(fn (ProjectPulseFact $fact) => $fact->toArray())
                ->values()
                ->all(),
        ])->values()->all();
    }

    public function urgentActions(Collection $facts): array
    {
        return $facts
            ->filter(fn (ProjectPulseFact $fact) => in_array($fact->priority, ['critical', 'warning'], true))
            ->take(10)
            ->map(fn (ProjectPulseFact $fact) => [
                'id' => $fact->id,
                'priority' => $fact->priority,
                'title' => $fact->title,
                'reason' => $fact->text,
                'expected_effect' => 'Снижение риска срыва работ и повторных согласований.',
                'project' => $fact->projectId ? [
                    'id' => $fact->projectId,
                    'name' => $fact->projectName,
                ] : null,
                'related_entity' => $fact->relatedEntity,
                'source' => 'rules',
            ])
            ->values()
            ->all();
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
                'title' => $fact->title,
                'subtitle' => $fact->projectName,
                'occurred_at' => $fact->occurredAt,
                'route' => $fact->relatedEntity['route'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function recommendationTitle(ProjectPulseFact $fact): string
    {
        return match ($fact->type) {
            'schedule' => 'Проверить план завершения проекта',
            'site_request' => 'Назначить ответственного по заявке',
            default => 'Проверить отклонение по проекту',
        };
    }

    private function recommendationAction(ProjectPulseFact $fact): string
    {
        return match ($fact->type) {
            'schedule' => 'Обновить план работ, ответственных и дату следующего контрольного шага.',
            'site_request' => 'Назначить исполнителя и зафиксировать срок реакции по заявке.',
            default => 'Сверить факт, ответственного и следующий шаг по связанному объекту.',
        };
    }
}
