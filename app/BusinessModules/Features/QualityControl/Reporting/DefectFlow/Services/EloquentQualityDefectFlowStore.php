<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Contracts\QualityDefectFlowStore;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO\QualityDefectFlowEvent;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowGapCode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class EloquentQualityDefectFlowStore implements QualityDefectFlowStore
{
    public function __construct(private QualityDefectFlowIdempotencyGuard $idempotency) {}

    public function append(QualityDefectFlowEvent $event): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('quality_defect_flow_owner_transaction_required');
        }

        $snapshot = $event->snapshot->canonical();
        $organizationId = (int) $snapshot['organization_id'];
        $projectId = (int) $snapshot['project_id'];
        $defectId = (int) $snapshot['quality_defect_id'];

        $defect = DB::table('quality_defects')
            ->where('id', $defectId)
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->lockForUpdate()
            ->first(['id']);
        if ($defect === null) {
            throw new LogicException('quality_defect_flow_lineage_mismatch');
        }

        $policyId = $this->policyId($event);
        $identity = [
            'organization_id' => $organizationId,
            'quality_defect_id' => $defectId,
            'event_kind' => $event->eventKind->value,
            'source_identity_hash' => $event->sourceIdentityHash(),
        ];
        $existing = DB::table('quality_defect_flow_events')
            ->where($identity)
            ->lockForUpdate()
            ->first(['event_id', 'source_hash']);
        if ($existing !== null) {
            return $this->idempotency->exactReplay(
                (string) $existing->event_id,
                (string) $existing->source_hash,
                $event->sourceHash(),
            );
        }

        $last = DB::table('quality_defect_flow_events')
            ->where('organization_id', $organizationId)
            ->where('quality_defect_id', $defectId)
            ->orderByDesc('sequence_no')
            ->lockForUpdate()
            ->first(['event_id', 'event_kind', 'sequence_no', 'occurred_at_utc']);

        if ($last === null && $event->eventKind !== QualityDefectFlowEventKind::CREATED) {
            return $this->appendMissingSourceGap($event, $policyId);
        }
        if ($last !== null && $event->eventKind === QualityDefectFlowEventKind::CREATED) {
            throw new LogicException('quality_defect_flow_initial_event_conflict');
        }
        if ($last !== null
            && new DateTimeImmutable((string) $last->occurred_at_utc) > $event->occurredAt) {
            throw new LogicException('quality_defect_flow_time_inversion');
        }

        $sequenceNo = $last === null ? 1 : ((int) $last->sequence_no) + 1;
        $lastOccurredAt = $last === null ? null : new DateTimeImmutable((string) $last->occurred_at_utc);
        do {
            $eventId = (string) Str::uuid7($event->occurredAt);
        } while ($last !== null
            && $lastOccurredAt?->format('U.u') === $event->occurredAt->format('U.u')
            && strcmp($eventId, (string) $last->event_id) <= 0);
        $sourceLink = $snapshot['source_link'];
        $attributes = [
            ...$identity,
            'event_id' => $eventId,
            'project_id' => $projectId,
            'contractor_id' => $snapshot['contractor_id'],
            'schedule_task_id' => $snapshot['schedule_task_id'],
            'acceptance_scope_id' => $sourceLink['acceptance_scope_id'] ?? null,
            'acceptance_session_id' => $sourceLink['acceptance_session_id'] ?? null,
            'actor_id' => $event->actorId,
            'assignee_id' => $snapshot['assignee_id'],
            'occurred_at_utc' => $event->occurredAtUtc(),
            'sequence_no' => $sequenceNo,
            'from_status' => $event->fromStatus?->value,
            'to_status' => $event->toStatus->value,
            'terminal_reason' => $event->terminalReason?->value,
            'policy_id' => $policyId,
            'policy_version' => $event->policy->version,
            'policy_hash' => $event->policyHash(),
            'business_snapshot' => QualityDefectFlowCanonicalJson::encode($snapshot),
            'source_identity' => QualityDefectFlowCanonicalJson::encode($event->sourceIdentity),
            'source_hash' => $event->sourceHash(),
            'evidence_hash' => $event->evidenceHash($eventId, $sequenceNo),
            'created_at' => now(new DateTimeZone('UTC')),
        ];

        DB::table('quality_defect_flow_events')->insert($attributes);

        return $eventId;
    }

    private function policyId(QualityDefectFlowEvent $event): int
    {
        $identity = [
            'policy_code' => $event->policy->policyCode,
            'version' => $event->policy->version,
        ];
        $policy = DB::table('quality_defect_flow_policies')
            ->where($identity)
            ->first(['id', 'policy_hash']);
        if ($policy === null) {
            DB::table('quality_defect_flow_policies')->insertOrIgnore([
                ...$identity,
                'canonical_policy' => QualityDefectFlowCanonicalJson::encode($event->policy->canonicalPolicy()),
                'policy_hash' => $event->policyHash(),
                'created_at' => now(new DateTimeZone('UTC')),
            ]);
            $policy = DB::table('quality_defect_flow_policies')
                ->where($identity)
                ->first(['id', 'policy_hash']);
        }
        if ($policy === null || ! hash_equals((string) $policy->policy_hash, $event->policyHash())) {
            throw new LogicException('quality_defect_flow_policy_conflict');
        }

        return (int) $policy->id;
    }

    private function appendMissingSourceGap(QualityDefectFlowEvent $event, int $policyId): string
    {
        $snapshot = $event->snapshot->canonical();
        $identity = [
            'organization_id' => (int) $snapshot['organization_id'],
            'quality_defect_id' => (int) $snapshot['quality_defect_id'],
            'gap_code' => QualityDefectFlowGapCode::SOURCE_CONTRACT_MISSING->value,
            'source_identity_hash' => $event->sourceIdentityHash(),
        ];
        $existing = DB::table('quality_defect_flow_gaps')
            ->where($identity)
            ->lockForUpdate()
            ->first(['gap_id', 'source_hash']);
        $sourceHash = QualityDefectFlowCanonicalJson::hash([
            'gap_code' => QualityDefectFlowGapCode::SOURCE_CONTRACT_MISSING->value,
            'organization_id' => (string) $identity['organization_id'],
            'policy_hash' => $event->policyHash(),
            'quality_defect_id' => (string) $identity['quality_defect_id'],
            'source_identity' => $event->sourceIdentity,
        ]);
        if ($existing !== null) {
            return $this->idempotency->exactReplay(
                (string) $existing->gap_id,
                (string) $existing->source_hash,
                $sourceHash,
            );
        }

        $gapId = (string) Str::uuid7();
        $evidenceHash = QualityDefectFlowCanonicalJson::hash([
            'gap_id' => $gapId,
            'policy_hash' => $event->policyHash(),
            'source_hash' => $sourceHash,
        ]);
        DB::table('quality_defect_flow_gaps')->insert([
            ...$identity,
            'gap_id' => $gapId,
            'project_id' => null,
            'detected_at_utc' => now(new DateTimeZone('UTC')),
            'policy_id' => $policyId,
            'policy_version' => $event->policy->version,
            'policy_hash' => $event->policyHash(),
            'source_identity' => QualityDefectFlowCanonicalJson::encode($event->sourceIdentity),
            'source_hash' => $sourceHash,
            'evidence_hash' => $evidenceHash,
            'created_at' => now(new DateTimeZone('UTC')),
        ]);

        return $gapId;
    }
}
