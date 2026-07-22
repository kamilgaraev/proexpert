<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectDeliveryData;
use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectCommandCenterPeriod;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProjectDeliveryBuilder
{
    public function build(
        Project $project,
        ProjectContext $projectContext,
        CarbonImmutable $asOf,
        array $problems,
        ProjectCommandCenterPeriod $period,
    ): ProjectDeliveryData {
        if ($project->getKey() !== $projectContext->projectId) {
            return ProjectDeliveryData::unavailable('project_command_center.delivery.project_context_mismatch');
        }

        $schedule = DB::table('project_schedules')
            ->where('project_id', $project->getKey())
            ->where('organization_id', $projectContext->organizationId)
            ->whereNull('deleted_at')
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'draft' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->orderByDesc('updated_at')
            ->first([
                'id', 'status', 'planned_end_date', 'baseline_end_date', 'actual_end_date', 'overall_progress_percent',
                'critical_path_calculated', 'critical_path_duration_days',
            ]);

        if ($schedule === null) {
            return ProjectDeliveryData::unavailable('project_command_center.delivery.schedule_unavailable');
        }

        $allTasks = DB::table('schedule_tasks')
            ->where('schedule_id', $schedule->id)
            ->where('organization_id', $projectContext->organizationId)
            ->whereNull('deleted_at')
            ->whereNotIn('task_type', ['summary', 'container']);

        $tasks = (clone $allTasks)
            ->when($period->hasRange(), static function ($query) use ($period): void {
                $query->whereBetween('planned_end_date', [$period->from->toDateString(), $period->to->toDateString()]);
            });

        $taskFacts = (clone $tasks)
            ->selectRaw("SUM(CASE WHEN planned_end_date < ? AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as overdue_count", [$asOf->toDateString()])
            ->selectRaw("SUM(CASE WHEN COALESCE(is_critical, false) = true AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as critical_count")
            ->selectRaw("SUM(CASE WHEN COALESCE(is_milestone_critical, false) = true AND status NOT IN ('completed', 'cancelled') THEN 1 ELSE 0 END) as critical_milestones_count")
            ->first();

        $forecastCompletion = null;
        if ((bool) $schedule->critical_path_calculated && $schedule->status !== 'completed') {
            $forecastCompletion = (clone $allTasks)
                ->where('is_critical', true)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->max('early_finish_date');
        }

        $pendingWorks = DB::table('completed_works')
            ->where('project_id', $project->getKey())
            ->where('organization_id', $projectContext->organizationId)
            ->whereNull('deleted_at')
            ->whereIn('status', ['pending', 'in_review'])
            ->when($period->hasRange(), static function ($query) use ($period): void {
                $query->whereBetween('created_at', [$period->from->startOfDay(), $period->to->endOfDay()]);
            })
            ->count();

        return $this->fromFacts([
            'schedule' => [
                'planned_end_date' => $schedule->planned_end_date,
                'baseline_end_date' => $schedule->baseline_end_date,
                'actual_end_date' => $schedule->actual_end_date,
                'status' => $schedule->status,
                'forecast_completion_date' => $forecastCompletion,
                'progress_percent' => $schedule->overall_progress_percent,
                'critical_path_calculated' => (bool) $schedule->critical_path_calculated,
                'critical_path_duration_days' => $schedule->critical_path_duration_days,
            ],
            'overdue_stages_count' => (int) ($taskFacts->overdue_count ?? 0),
            'critical_work_count' => (int) ($taskFacts->critical_count ?? 0),
            'critical_milestones_count' => (int) ($taskFacts->critical_milestones_count ?? 0),
            'pending_work_confirmations_count' => $pendingWorks,
            'active_safety_findings_count' => $this->safetyFindings($problems),
        ], $asOf, (int) $project->getKey());
    }

    /**
     * Kept public so delivery status can be covered without a database connection.
     */
    public function fromFacts(array $facts, CarbonImmutable $asOf, int $projectId): ProjectDeliveryData
    {
        $schedule = $facts['schedule'] ?? null;
        if (! is_array($schedule) || empty($schedule['planned_end_date'])) {
            return ProjectDeliveryData::unavailable('project_command_center.delivery.schedule_unavailable');
        }

        $plannedEnd = CarbonImmutable::parse((string) $schedule['planned_end_date'])->startOfDay();
        $baselineEnd = ! empty($schedule['baseline_end_date'])
            ? CarbonImmutable::parse((string) $schedule['baseline_end_date'])->startOfDay()
            : null;
        $scheduleDeviation = $baselineEnd === null ? null : $baselineEnd->diffInDays($plannedEnd, false);
        $riskReasons = $this->riskReasons($facts, $scheduleDeviation);
        $forecastCompletion = ! empty($schedule['forecast_completion_date'])
            ? CarbonImmutable::parse((string) $schedule['forecast_completion_date'])->startOfDay()
            : null;

        return ProjectDeliveryData::available([
            'forecast_completion' => [
                'available' => $forecastCompletion !== null,
                'reason_key' => $forecastCompletion === null ? 'project_command_center.delivery.forecast_completion_unavailable' : null,
                'date' => $forecastCompletion?->toDateString(),
            ],
            'schedule_deviation_days' => $scheduleDeviation,
            'progress_percent' => $this->number($schedule['progress_percent'] ?? null),
            'critical_path' => [
                'available' => (bool) ($schedule['critical_path_calculated'] ?? false),
                'duration_days' => isset($schedule['critical_path_duration_days']) ? (int) $schedule['critical_path_duration_days'] : null,
            ],
            'counts' => [
                'overdue_stages' => (int) ($facts['overdue_stages_count'] ?? 0),
                'critical_works' => (int) ($facts['critical_work_count'] ?? 0),
                'critical_milestones' => (int) ($facts['critical_milestones_count'] ?? 0),
                'pending_work_confirmations' => (int) ($facts['pending_work_confirmations_count'] ?? 0),
                'active_safety_findings' => (int) ($facts['active_safety_findings_count'] ?? 0),
                'critical_materials' => null,
            ],
            'data_completeness' => [
                'critical_materials' => [
                    'available' => false,
                    'reason_key' => 'project_command_center.delivery.critical_materials_unavailable',
                ],
            ],
            'risk_reasons' => $riskReasons,
            'actions' => [
                'overdue_stages' => $this->action("/projects/{$projectId}/schedules", $projectId),
                'pending_work_confirmations' => $this->action('/workflow/completed-works', $projectId),
                'active_safety_findings' => $this->action('/safety-management', $projectId),
            ],
        ]);
    }

    private function safetyFindings(array $problems): int
    {
        return count(array_filter(
            $problems['items'] ?? [],
            static fn (mixed $item): bool => is_array($item) && ($item['module'] ?? null) === 'safety',
        ));
    }

    private function riskReasons(array $facts, ?int $scheduleDeviation): array
    {
        $reasons = [];
        $map = [
            'overdue_stages_count' => 'project_command_center.delivery.overdue_stages',
            'critical_work_count' => 'project_command_center.delivery.critical_works',
            'critical_milestones_count' => 'project_command_center.delivery.critical_milestones',
            'pending_work_confirmations_count' => 'project_command_center.delivery.pending_work_confirmations',
            'active_safety_findings_count' => 'project_command_center.delivery.active_safety_findings',
        ];

        foreach ($map as $field => $key) {
            if ((int) ($facts[$field] ?? 0) > 0) {
                $reasons[] = $key;
            }
        }

        if ($scheduleDeviation !== null && $scheduleDeviation > 0) {
            $reasons[] = 'project_command_center.delivery.schedule_deviation';
        }

        return $reasons;
    }

    private function action(string $route, int $projectId): array
    {
        return ['route' => $route, 'query' => ['project_id' => $projectId]];
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 2) : null;
    }
}
