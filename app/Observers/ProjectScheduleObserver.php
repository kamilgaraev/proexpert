<?php

namespace App\Observers;

use App\Models\ProjectSchedule;
use App\Services\Analytics\EVMService;
use Illuminate\Support\Facades\Log;

class ProjectScheduleObserver
{
    public function created(ProjectSchedule $schedule): void
    {
        $this->invalidateEVMCache($schedule);
    }

    public function updated(ProjectSchedule $schedule): void
    {
        $this->invalidateEVMCache($schedule, true);
    }

    public function deleted(ProjectSchedule $schedule): void
    {
        $this->invalidateEVMCache($schedule, true);
    }

    public function restored(ProjectSchedule $schedule): void
    {
        $this->invalidateEVMCache($schedule);
    }

    public function forceDeleted(ProjectSchedule $schedule): void
    {
        $this->invalidateEVMCache($schedule, true);
    }

    private function invalidateEVMCache(ProjectSchedule $schedule, bool $includeOriginal = false): void
    {
        try {
            $projectIds = collect([$schedule->project_id]);

            if ($includeOriginal) {
                $projectIds->push($schedule->getOriginal('project_id'));
            }

            $projectIds
                ->filter(fn (mixed $projectId): bool => $projectId !== null && (int) $projectId > 0)
                ->map(fn (mixed $projectId): int => (int) $projectId)
                ->unique()
                ->each(fn (int $projectId): mixed => app(EVMService::class)->invalidateCache($projectId));
        } catch (\Exception $e) {
            Log::warning('Failed to invalidate EVM cache for project schedule', [
                'schedule_id' => $schedule->id,
                'project_id' => $schedule->project_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
