<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;

final readonly class PublishedCommitment
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public string $windowStart,
        public string $windowEnd,
        public string $planningTimezone,
        public string $scheduleRevisionHash,
        public string $policyHash,
        public int $publishedByUserId,
        public string $publishedAtUtc,
        public array $tasks,
        public string $contentHash,
    ) {}

    public function canonicalSnapshot(): array
    {
        return LookaheadReadinessCanonicalJson::sort([
            'organization_id' => (string) $this->organizationId,
            'planning_timezone' => $this->planningTimezone,
            'policy_hash' => $this->policyHash,
            'project_id' => (string) $this->projectId,
            'published_at_utc' => $this->publishedAtUtc,
            'published_by_user_id' => (string) $this->publishedByUserId,
            'schedule_id' => (string) $this->scheduleId,
            'schedule_revision_hash' => $this->scheduleRevisionHash,
            'tasks' => $this->tasks,
            'window_end' => $this->windowEnd,
            'window_start' => $this->windowStart,
        ]);
    }
}
