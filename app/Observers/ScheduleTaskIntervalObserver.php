<?php

namespace App\Observers;

use App\Models\ScheduleTaskInterval;

class ScheduleTaskIntervalObserver
{
    /**
     * Handle the ScheduleTaskInterval "created" event.
     */
    public function created(ScheduleTaskInterval $interval): void
    {
        $this->syncTaskDates($interval);
    }

    /**
     * Handle the ScheduleTaskInterval "updated" event.
     */
    public function updated(ScheduleTaskInterval $interval): void
    {
        $this->syncTaskDates($interval);
    }

    /**
     * Handle the ScheduleTaskInterval "deleted" event.
     */
    public function deleted(ScheduleTaskInterval $interval): void
    {
        $this->syncTaskDates($interval);
    }

    /**
     * Sync parent task dates.
     */
    private function syncTaskDates(ScheduleTaskInterval $interval): void
    {
        if ($interval->task) {
            $interval->task->syncDatesFromIntervals();
        }
    }
}
