<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\CommitmentDraft;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\PublishedCommitment;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessPolicyDefinition;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ScheduleRevisionDraft;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class CommitmentFactory
{
    public function publish(
        CommitmentDraft $draft,
        ScheduleRevisionDraft $scheduleRevision,
        string $scheduleRevisionHash,
        ReadinessPolicyDefinition $policy,
        int $actorId,
        DateTimeImmutable $publishedAt,
    ): PublishedCommitment {
        if ($actorId <= 0
            || $draft->organizationId !== $scheduleRevision->organizationId
            || $draft->projectId !== $scheduleRevision->projectId
            || $draft->scheduleId !== $scheduleRevision->scheduleId
            || $draft->organizationId !== $policy->organizationId
            || $draft->planningTimezone !== $scheduleRevision->planningTimezone
            || preg_match('/^[a-f0-9]{64}$/D', $scheduleRevisionHash) !== 1) {
            throw new InvalidArgumentException('lookahead_readiness_commitment_lineage_invalid');
        }
        $sourceTasks = array_column($scheduleRevision->tasks, null, 'external_id');
        $tasks = $draft->tasks;
        $seen = [];
        foreach ($tasks as $task) {
            $externalId = $task['schedule_task_external_id'] ?? null;
            $start = DateTimeImmutable::createFromFormat('!Y-m-d', $task['committed_start'] ?? '');
            $end = DateTimeImmutable::createFromFormat('!Y-m-d', $task['committed_end'] ?? '');
            if (! is_string($externalId)
                || isset($seen[$externalId])
                || ! isset($sourceTasks[$externalId])
                || ! $start instanceof DateTimeImmutable
                || ! $end instanceof DateTimeImmutable
                || $start > $end
                || $task['committed_start'] < $draft->windowStart
                || $task['committed_start'] > $draft->windowEnd
                || ! is_string($task['inclusion_reason'] ?? null)
                || $task['inclusion_reason'] === '') {
                throw new InvalidArgumentException('lookahead_readiness_commitment_task_invalid');
            }
            $seen[$externalId] = true;
        }
        usort($tasks, static fn (array $left, array $right): int => [
            $left['committed_start'],
            $left['schedule_task_external_id'],
        ] <=> [
            $right['committed_start'],
            $right['schedule_task_external_id'],
        ]);
        $publishedAtUtc = $publishedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $canonical = [
            'organization_id' => (string) $draft->organizationId,
            'planning_timezone' => $draft->planningTimezone,
            'policy_hash' => $policy->hash(),
            'project_id' => (string) $draft->projectId,
            'published_at_utc' => $publishedAtUtc,
            'published_by_user_id' => (string) $actorId,
            'schedule_id' => (string) $draft->scheduleId,
            'schedule_revision_hash' => $scheduleRevisionHash,
            'tasks' => $tasks,
            'window_end' => $draft->windowEnd,
            'window_start' => $draft->windowStart,
        ];

        return new PublishedCommitment(
            $draft->organizationId,
            $draft->projectId,
            $draft->scheduleId,
            $draft->windowStart,
            $draft->windowEnd,
            $draft->planningTimezone,
            $scheduleRevisionHash,
            $policy->hash(),
            $actorId,
            $publishedAtUtc,
            $tasks,
            LookaheadReadinessCanonicalJson::hash($canonical),
        );
    }
}
