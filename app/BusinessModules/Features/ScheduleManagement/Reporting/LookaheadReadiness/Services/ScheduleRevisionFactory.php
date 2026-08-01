<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;

final class ScheduleRevisionFactory
{
    public function contentHash(ScheduleRevisionDraft $draft): string
    {
        return LookaheadReadinessCanonicalJson::hash($this->canonicalSnapshot($draft));
    }

    public function canonicalSnapshot(ScheduleRevisionDraft $draft): array
    {
        $tasks = $draft->tasks;
        $dependencies = $draft->dependencies;
        usort($tasks, static fn (array $left, array $right): int => strcmp($left['external_id'], $right['external_id']));
        usort($dependencies, static fn (array $left, array $right): int => [
            $left['predecessor_external_id'],
            $left['successor_external_id'],
            $left['type'],
            $left['lag_minutes'],
        ] <=> [
            $right['predecessor_external_id'],
            $right['successor_external_id'],
            $right['type'],
            $right['lag_minutes'],
        ]);

        return LookaheadReadinessCanonicalJson::sort([
            'calendar' => $draft->calendar,
            'dependencies' => $dependencies,
            'organization_id' => (string) $draft->organizationId,
            'planning_timezone' => $draft->planningTimezone,
            'project_id' => (string) $draft->projectId,
            'schedule_id' => (string) $draft->scheduleId,
            'source_watermark' => $draft->sourceWatermark,
            'tasks' => $tasks,
        ]);
    }
}
