<?php

namespace App\BusinessModules\Features\ScheduleManagement\Events;

use App\Models\ProjectSchedule;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduleSyncedWithEstimate
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProjectSchedule $schedule,
        public array $syncResults
    ) {}
}

