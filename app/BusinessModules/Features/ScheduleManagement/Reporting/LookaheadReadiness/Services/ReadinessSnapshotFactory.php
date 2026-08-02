<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessEvaluation;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO\ReadinessSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class ReadinessSnapshotFactory
{
    public function seal(
        array $pins,
        ReadinessEvaluation $evaluation,
        DateTimeImmutable $calculatedAt,
        ?array $actualComparison,
    ): ReadinessSnapshot {
        foreach ([
            'organization_id',
            'project_id',
            'schedule_id',
            'commitment_revision_id',
            'commitment_task_id',
            'snapshot_revision',
            'policy_id',
        ] as $key) {
            if (! is_int($pins[$key] ?? null) || $pins[$key] <= 0) {
                throw new InvalidArgumentException('lookahead_readiness_snapshot_lineage_invalid');
            }
        }
        foreach (['policy_hash', 'schedule_revision_hash', 'commitment_revision_hash'] as $key) {
            if (! is_string($pins[$key] ?? null) || strlen($pins[$key]) !== 64) {
                throw new InvalidArgumentException('lookahead_readiness_snapshot_pin_invalid');
            }
        }
        if (! is_string($pins['source_watermark'] ?? null) || $pins['source_watermark'] === '') {
            throw new InvalidArgumentException('lookahead_readiness_snapshot_source_invalid');
        }
        if (preg_match('/^[0-9a-f-]{36}$/D', $pins['evaluation_event_id'] ?? '') !== 1
            || ! is_int($pins['sealed_by_actor_id'] ?? null)
            || $pins['sealed_by_actor_id'] <= 0
            || preg_match('/^[a-f0-9]{64}$/D', $pins['authorization_decision_hash'] ?? '') !== 1
            || ! is_string($pins['as_of_utc'] ?? null)
            || $pins['as_of_utc'] === '') {
            throw new InvalidArgumentException('lookahead_readiness_snapshot_evaluation_link_invalid');
        }
        if ($actualComparison !== null
            && (array_keys($actualComparison) !== ['source_kind', 'accepted_event_id']
                || ($actualComparison['source_kind'] ?? null) !== 'construction_journal_acceptance'
                || preg_match('/^[0-9a-f-]{36}$/D', $actualComparison['accepted_event_id'] ?? '') !== 1)) {
            throw new InvalidArgumentException('lookahead_readiness_actual_comparison_invalid');
        }
        if ($actualComparison !== null) {
            throw new InvalidArgumentException('lookahead_readiness_actual_source_unavailable');
        }
        $calculatedAtUtc = $calculatedAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        if ($calculatedAtUtc < $pins['as_of_utc']) {
            throw new InvalidArgumentException('lookahead_readiness_snapshot_causality_invalid');
        }
        $readinessPayload = [
            'as_of_utc' => $pins['as_of_utc'],
            'authorization_decision_hash' => $pins['authorization_decision_hash'],
            'blocker_event_ids' => array_values($pins['blocker_event_ids'] ?? []),
            'calculated_at_utc' => $calculatedAtUtc,
            'commitment_revision_hash' => $pins['commitment_revision_hash'],
            'commitment_revision_id' => (string) $pins['commitment_revision_id'],
            'commitment_task_id' => (string) $pins['commitment_task_id'],
            'component_outcomes' => $evaluation->componentOutcomes,
            'evaluation_event_id' => $pins['evaluation_event_id'],
            'organization_id' => (string) $pins['organization_id'],
            'policy_hash' => $pins['policy_hash'],
            'project_id' => (string) $pins['project_id'],
            'reason_codes' => $evaluation->reasonCodes,
            'schedule_id' => (string) $pins['schedule_id'],
            'schedule_revision_hash' => $pins['schedule_revision_hash'],
            'sealed_by_actor_id' => (string) $pins['sealed_by_actor_id'],
            'snapshot_revision' => $pins['snapshot_revision'],
            'source_watermark' => $pins['source_watermark'],
            'state' => $evaluation->state->value,
            'waiver_event_ids' => array_values($pins['waiver_event_ids'] ?? []),
        ];
        $readinessHash = LookaheadReadinessCanonicalJson::hash($readinessPayload);
        $snapshotHash = LookaheadReadinessCanonicalJson::hash([
            ...$readinessPayload,
            'actual_comparison' => $actualComparison,
            'readiness_hash' => $readinessHash,
        ]);

        return new ReadinessSnapshot(
            $pins['organization_id'],
            $pins['project_id'],
            $pins['schedule_id'],
            $pins['commitment_revision_id'],
            $pins['commitment_task_id'],
            $pins['snapshot_revision'],
            $evaluation->state,
            $evaluation->componentOutcomes,
            $evaluation->reasonCodes,
            $pins['policy_hash'],
            $pins['schedule_revision_hash'],
            $pins['commitment_revision_hash'],
            $pins['source_watermark'],
            $calculatedAtUtc,
            array_values($pins['blocker_event_ids'] ?? []),
            array_values($pins['waiver_event_ids'] ?? []),
            $actualComparison,
            $readinessHash,
            $snapshotHash,
            $pins['evaluation_event_id'],
            $pins['sealed_by_actor_id'],
            $pins['authorization_decision_hash'],
            $pins['as_of_utc'],
        );
    }
}
