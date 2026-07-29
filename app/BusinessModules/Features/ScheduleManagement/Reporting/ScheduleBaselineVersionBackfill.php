<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting;

use App\BusinessModules\Features\ScheduleManagement\Models\ScheduleBaselineVersion;
use App\Models\ProjectSchedule;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class ScheduleBaselineVersionBackfill
{
    public function __construct(private BaselineScheduleSnapshotService $baselines)
    {
    }

    public function run(int $organizationId, array $projectIds): array
    {
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('schedule_baseline_backfill_scope_invalid');
        }

        $versionIds = [];
        $schedules = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('baseline_saved_at')
            ->whereNotNull('baseline_saved_by_user_id')
            ->orderBy('id')
            ->get();
        foreach ($schedules as $schedule) {
            $exists = ScheduleBaselineVersion::query()
                ->where('organization_id', $organizationId)
                ->where('schedule_id', $schedule->id)
                ->exists();
            if ($exists) {
                continue;
            }
            $versionIds[] = (int) $this->baselines->capture(
                $schedule,
                (int) $schedule->baseline_saved_by_user_id,
                new DateTimeImmutable($schedule->baseline_saved_at->format(DATE_ATOM)),
            )->id;
        }

        return $versionIds;
    }
}
