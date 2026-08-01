<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

final class ScheduleSourceWatermark
{
    public function make(array $schedule, array $tasks, array $dependencies): string
    {
        usort($tasks, static fn (array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']));
        usort(
            $dependencies,
            static fn (array $left, array $right): int => ((int) $left['id']) <=> ((int) $right['id']),
        );

        return 'schedule:'.$schedule['id'].':'.LookaheadReadinessCanonicalJson::hash([
            'dependencies' => $dependencies,
            'schedule' => $schedule,
            'tasks' => $tasks,
        ]);
    }
}
