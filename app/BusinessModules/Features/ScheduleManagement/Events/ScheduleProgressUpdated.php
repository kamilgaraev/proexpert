<?php

namespace App\BusinessModules\Features\ScheduleManagement\Events;

use App\Models\ProjectSchedule;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduleProgressUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProjectSchedule $schedule,
        public float $oldProgress,
        public float $newProgress
    ) {}
}

