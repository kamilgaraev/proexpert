<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;

class ProjectPulseFormatter
{
    public function format(ProjectPulseReport $report): array
    {
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
            'status' => $report->status,
            'ai_mode' => [
                'status' => $report->ai_status,
                'provider' => $report->ai_provider,
                'message' => $this->aiMessage($report),
            ],
            'summary' => $report->summary ?? [],
            'metrics' => $report->metrics ?? [],
            'urgent_actions' => $report->urgent_actions ?? [],
            'risk_groups' => $report->risk_groups ?? [],
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
            'generated_at' => $report->generated_at?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
        ];
    }

    private function aiMessage(ProjectPulseReport $report): string
    {
        return match ($report->ai_status) {
            'active' => 'Рекомендации усилены AI на основе фактов из системы.',
            'unavailable' => 'AI сейчас недоступен. Показаны системные факты и базовые рекомендации.',
            default => 'AI-обобщение отключено в настройках или запросе.',
        };
    }
}
