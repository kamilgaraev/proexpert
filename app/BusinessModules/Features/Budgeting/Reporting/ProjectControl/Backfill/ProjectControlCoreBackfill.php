<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Backfill;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Listeners\CaptureScheduleBaselineVersion;
use App\Models\ProjectSchedule;
use InvalidArgumentException;

final readonly class ProjectControlCoreBackfill
{
    public function __construct(private CaptureScheduleBaselineVersion $capture)
    {
    }

    public function run(int $organizationId, array $projectIds): array
    {
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('project_control_backfill_scope_invalid');
        }

        $captured = [];
        $gapCount = 0;
        $scheduleIds = ProjectSchedule::query()
            ->where('organization_id', $organizationId)
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('baseline_saved_at')
            ->orderBy('id')
            ->pluck('id');
        foreach ($scheduleIds as $scheduleId) {
            $baseline = $this->capture->capture((int) $scheduleId);
            if ($baseline === null) {
                $gapCount++;
                continue;
            }
            $captured[] = (int) $baseline->id;
        }

        return [
            'captured_ids' => $captured,
            'gap_count' => $gapCount,
        ];
    }
}
