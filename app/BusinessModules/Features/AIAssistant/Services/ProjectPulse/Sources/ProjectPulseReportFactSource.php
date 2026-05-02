<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources;

use App\BusinessModules\Features\AIAssistant\Contracts\ProjectPulse\ProjectPulseFactSourceInterface;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseFact;
use App\BusinessModules\Features\AIAssistant\Services\ProjectPulse\Sources\Concerns\BuildsProjectPulseFacts;
use Illuminate\Support\Collection;

class ProjectPulseReportFactSource implements ProjectPulseFactSourceInterface
{
    use BuildsProjectPulseFacts;

    public function key(): string
    {
        return 'reports';
    }

    public function collect(ProjectPulseContext $context): Collection
    {
        return collect()
            ->merge($this->failedCustomReportExecutions($context))
            ->merge($this->overdueCustomReportSchedules($context))
            ->values();
    }

    private function failedCustomReportExecutions(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('custom_report_executions')) {
            return $this->empty();
        }

        return $this->table($context, 'custom_report_executions')
            ->whereIn('custom_report_executions.status', ['failed', 'error'])
            ->whereBetween('custom_report_executions.created_at', [$context->from->copy()->subDays(7), $context->to])
            ->limit($this->limit())
            ->get(['custom_report_executions.id', 'custom_report_executions.status', 'custom_report_executions.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'custom_report_execution:' . $row->id . ':failed',
                type: 'custom_report_execution',
                priority: 'warning',
                title: 'Отчет не сформировался',
                text: 'Запуск пользовательского отчета завершился ошибкой.',
                relatedEntity: [
                    'type' => 'custom_report_execution',
                    'id' => (int) $row->id,
                    'label' => 'Запуск отчета #' . $row->id,
                    'route' => '/reports/custom-reports',
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'report',
                status: $row->status,
                nextAction: 'Проверить настройки отчета и повторить формирование.',
                primaryAction: [
                    'label' => 'Открыть отчеты',
                    'route' => '/reports/custom-reports',
                    'permission' => 'reports.view',
                ],
            ))->values();
    }

    private function overdueCustomReportSchedules(ProjectPulseContext $context): Collection
    {
        if (!$this->hasTable('custom_report_schedules') || !$this->hasColumn('custom_report_schedules', 'next_run_at')) {
            return $this->empty();
        }

        return $this->table($context, 'custom_report_schedules')
            ->whereDate('custom_report_schedules.next_run_at', '<', $context->date->toDateString())
            ->limit($this->limit())
            ->get(['custom_report_schedules.id', 'custom_report_schedules.next_run_at', 'custom_report_schedules.created_at'])
            ->map(fn ($row) => new ProjectPulseFact(
                id: 'custom_report_schedule:' . $row->id . ':overdue',
                type: 'custom_report_schedule',
                priority: 'warning',
                title: 'Плановый отчет не выполнен',
                text: 'Плановое формирование отчета просрочено.',
                relatedEntity: [
                    'type' => 'custom_report_schedule',
                    'id' => (int) $row->id,
                    'label' => 'Расписание отчета #' . $row->id,
                    'route' => '/reports',
                ],
                occurredAt: $this->dateString($row->created_at),
                source: $this->key(),
                category: 'report',
                status: 'overdue',
                nextAction: 'Проверить расписание отчета и актуальность получателей.',
                deadline: (string) $row->next_run_at,
                ageDays: $this->ageDays($context, $row->next_run_at),
            ))->values();
    }
}
