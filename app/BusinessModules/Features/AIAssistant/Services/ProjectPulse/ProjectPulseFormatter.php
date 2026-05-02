<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;

class ProjectPulseFormatter
{
    public function format(ProjectPulseReport $report): array
    {
        $report->loadMissing('project');

        $facts = $report->raw_facts ?? [];
        $categories = $this->categories($report);
        $groups = $report->risk_groups ?? [];
        $nextActions = $report->urgent_actions ?? [];
        $metrics = $this->metrics($report, $categories, $nextActions, $facts);

        return [
            'id' => $report->id,
            'report_date' => $report->report_date?->toDateString(),
            'period' => [
                'preset' => $report->period_preset,
                'from' => $report->period_from?->toIso8601String(),
                'to' => $report->period_to?->toIso8601String(),
            ],
            'scope' => [
                'type' => $report->scope_type,
                'organization_id' => $report->organization_id,
                'project_id' => $report->project_id,
            ],
            'project_id' => $report->project_id,
            'project' => $report->project ? [
                'id' => $report->project->id,
                'name' => $report->project->name,
            ] : null,
            'status' => $report->status,
            'ai_mode' => [
                'status' => $report->ai_status,
                'provider' => $report->ai_provider,
                'message' => $this->aiMessage($report),
            ],
            'summary' => $report->summary ?? [],
            'categories' => $categories,
            'groups' => $groups,
            'facts' => $facts,
            'next_actions' => $nextActions,
            'metrics' => $metrics,
            'urgent_actions' => $nextActions,
            'risk_groups' => $groups,
            'finance' => $report->finance ?? [
                'performed_amount' => 0,
                'paid_amount' => 0,
                'pending_acts_amount' => 0,
                'deviation_items' => [],
            ],
            'activity' => $report->activity ?? [],
            'recommendations' => $report->recommendations ?? [],
            'generated_at' => $report->generated_at?->toIso8601String(),
        ];
    }

    public function listItem(ProjectPulseReport $report): array
    {
        $categories = $this->categories($report);
        $nextActions = $report->urgent_actions ?? [];

        return [
            'id' => $report->id,
            'report_date' => $report->report_date?->toDateString(),
            'period' => [
                'preset' => $report->period_preset,
                'from' => $report->period_from?->toIso8601String(),
                'to' => $report->period_to?->toIso8601String(),
            ],
            'scope' => [
                'type' => $report->scope_type,
                'organization_id' => $report->organization_id,
                'project_id' => $report->project_id,
            ],
            'project_id' => $report->project_id,
            'project' => $report->project ? [
                'id' => $report->project->id,
                'name' => $report->project->name,
            ] : null,
            'status' => $report->status,
            'ai_status' => $report->ai_status,
            'ai_mode' => [
                'status' => $report->ai_status,
                'provider' => $report->ai_provider,
                'message' => $this->aiMessage($report),
            ],
            'summary' => $report->summary,
            'categories' => $categories,
            'category_summary' => $categories,
            'next_action_count' => count($nextActions),
            'generated_at' => $report->generated_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    private function categories(ProjectPulseReport $report): array
    {
        $metrics = $report->metrics ?? [];

        if ($this->looksLikeCategories($metrics)) {
            return $metrics;
        }

        return collect($report->raw_facts ?? [])
            ->filter(fn (array $fact): bool => isset($fact['category']))
            ->groupBy('category')
            ->map(fn ($facts, string $category): array => [
                'key' => $category,
                'label' => $this->categoryLabel($category),
                'status' => $facts->contains(fn (array $fact): bool => ($fact['priority'] ?? null) === 'critical')
                    ? 'critical'
                    : ($facts->contains(fn (array $fact): bool => ($fact['priority'] ?? null) === 'warning') ? 'warning' : 'good'),
                'critical_count' => $facts->where('priority', 'critical')->count(),
                'warning_count' => $facts->where('priority', 'warning')->count(),
                'info_count' => $facts->where('priority', 'info')->count(),
                'amount' => $facts->sum(fn (array $fact): float => (float) ($fact['amount'] ?? 0)),
            ])
            ->values()
            ->all();
    }

    private function metrics(ProjectPulseReport $report, array $categories, array $nextActions, array $facts): array
    {
        $storedMetrics = $report->metrics ?? [];

        if ($storedMetrics !== [] && !$this->looksLikeCategories($storedMetrics)) {
            return $storedMetrics;
        }

        return [
            [
                'key' => 'facts_total',
                'label' => 'Фактов в пульсе',
                'value' => count($facts),
                'tone' => 'primary',
            ],
            [
                'key' => 'critical_facts',
                'label' => 'Критичные события',
                'value' => array_sum(array_map(fn (array $category): int => (int) ($category['critical_count'] ?? 0), $categories)),
                'tone' => 'error',
            ],
            [
                'key' => 'warning_facts',
                'label' => 'Требуют внимания',
                'value' => array_sum(array_map(fn (array $category): int => (int) ($category['warning_count'] ?? 0), $categories)),
                'tone' => 'warning',
            ],
            [
                'key' => 'next_actions',
                'label' => 'Ближайшие действия',
                'value' => count($nextActions),
                'tone' => 'info',
            ],
        ];
    }

    private function looksLikeCategories(array $items): bool
    {
        return $items !== [] && isset($items[0]['critical_count'], $items[0]['warning_count']);
    }

    private function categoryLabel(string $category): string
    {
        $labels = config('ai-assistant.project_pulse.categories', []);

        return is_array($labels) ? (string) ($labels[$category] ?? $category) : $category;
    }

    private function aiMessage(ProjectPulseReport $report): string
    {
        return match ($report->ai_status) {
            'active' => 'Рекомендации усилены ИИ на основе фактов из системы.',
            'unavailable' => 'ИИ сейчас недоступен. Показаны системные факты и базовые рекомендации.',
            default => 'Рекомендации подготовлены по правилам на основе данных системы.',
        };
    }
}
