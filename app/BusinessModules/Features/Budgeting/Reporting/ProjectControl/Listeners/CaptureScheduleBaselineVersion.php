<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Listeners;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion;
use App\Models\ProjectSchedule;
use Illuminate\Support\Facades\DB;

final readonly class CaptureScheduleBaselineVersion
{
    public function capture(int $scheduleId): ?ProjectControlBaselineVersion
    {
        return DB::transaction(function () use ($scheduleId): ?ProjectControlBaselineVersion {
            $schedule = ProjectSchedule::query()
                ->with(['tasks' => static fn ($query) => $query
                    ->whereIn('task_type', ['task', 'milestone'])
                    ->orderBy('id')])
                ->lockForUpdate()
                ->find($scheduleId);
            if ($schedule === null
                || $schedule->baseline_saved_at === null
                || (int) $schedule->baseline_saved_by_user_id < 1
                || $schedule->tasks->isEmpty()
            ) {
                return null;
            }

            $rows = [];
            foreach ($schedule->tasks as $task) {
                $source = (array) $task->custom_fields;
                $curve = $source['baseline_curve'] ?? null;
                $curveVersion = $source['baseline_curve_version'] ?? null;
                $currency = $source['baseline_currency'] ?? null;
                $taxBasis = $source['baseline_tax_basis'] ?? null;
                if ($task->baseline_start_date === null
                    || $task->baseline_end_date === null
                    || $task->estimated_cost === null
                    || !is_array($curve)
                    || !array_is_list($curve)
                    || $curve === []
                    || !is_string($curveVersion)
                    || trim($curveVersion) === ''
                    || !is_string($currency)
                    || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
                    || !is_string($taxBasis)
                    || trim($taxBasis) === ''
                ) {
                    return null;
                }

                $rows[] = [
                    'bac' => (string) $task->estimated_cost,
                    'baseline_curve' => $curve,
                    'baseline_curve_version' => $curveVersion,
                    'baseline_end' => $task->baseline_end_date->format('Y-m-d'),
                    'baseline_start' => $task->baseline_start_date->format('Y-m-d'),
                    'currency' => $currency,
                    'task_id' => (int) $task->id,
                    'tax_basis' => $taxBasis,
                    'wbs_code' => $task->wbs_code,
                ];
            }

            $payload = [
                'approved_at' => $schedule->baseline_saved_at->format(DATE_ATOM),
                'approved_by' => (int) $schedule->baseline_saved_by_user_id,
                'project_id' => (int) $schedule->project_id,
                'rows' => $rows,
                'schedule_id' => (int) $schedule->id,
            ];
            $sourceHash = hash('sha256', CanonicalJson::encode($payload));
            $existing = ProjectControlBaselineVersion::query()
                ->where('organization_id', $schedule->organization_id)
                ->where('project_id', $schedule->project_id)
                ->where('schedule_id', $schedule->id)
                ->where('source_hash', $sourceHash)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $version = (int) ProjectControlBaselineVersion::query()
                ->where('organization_id', $schedule->organization_id)
                ->where('project_id', $schedule->project_id)
                ->where('schedule_id', $schedule->id)
                ->lockForUpdate()
                ->max('version_number');

            return ProjectControlBaselineVersion::query()->create([
                'organization_id' => (int) $schedule->organization_id,
                'project_id' => (int) $schedule->project_id,
                'schedule_id' => (int) $schedule->id,
                'version_number' => $version + 1,
                'approved_at' => $schedule->baseline_saved_at,
                'approved_by' => (int) $schedule->baseline_saved_by_user_id,
                'source_hash' => $sourceHash,
                'source_payload' => $payload,
            ]);
        });
    }
}
