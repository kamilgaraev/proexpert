<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\DTO;

use App\BusinessModules\Features\QualityControl\Enums\QualityDefectStatusEnum;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowEventKind;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Enums\QualityDefectFlowTerminalReason;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services\QualityDefectFlowCanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class QualityDefectFlowEvent
{
    public function __construct(
        public QualityDefectFlowEventKind $eventKind,
        public ?QualityDefectStatusEnum $fromStatus,
        public QualityDefectStatusEnum $toStatus,
        public ?int $actorId,
        public DateTimeImmutable $occurredAt,
        public QualityDefectFlowSnapshot $snapshot,
        public array $sourceIdentity,
        public QualityDefectFlowPolicyDefinition $policy,
        public ?QualityDefectFlowTerminalReason $terminalReason = null,
    ) {
        if (! $policy->allows($eventKind, $fromStatus, $toStatus, $terminalReason)) {
            throw new InvalidArgumentException('quality_defect_flow_transition_not_allowed');
        }
        if ($actorId !== null && $actorId <= 0) {
            throw new InvalidArgumentException('quality_defect_flow_actor_invalid');
        }
        if (array_keys($sourceIdentity) !== ['kind', 'id']
            || ($sourceIdentity['kind'] ?? null) !== 'quality_defect_status_history'
            || ! is_string($sourceIdentity['id'] ?? null)
            || preg_match('/^[1-9][0-9]*$/D', $sourceIdentity['id']) !== 1) {
            throw new InvalidArgumentException('quality_defect_flow_source_identity_invalid');
        }
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public function policyHash(): string
    {
        return $this->policy->hash();
    }

    public function sourceIdentityHash(): string
    {
        return QualityDefectFlowCanonicalJson::hash($this->sourceIdentity);
    }

    public function sourceHash(): string
    {
        return QualityDefectFlowCanonicalJson::hash([
            'actor_id' => $this->actorId === null ? null : (string) $this->actorId,
            'business_snapshot' => $this->snapshot->canonical(),
            'event_kind' => $this->eventKind->value,
            'from_status' => $this->fromStatus?->value,
            'occurred_at_utc' => $this->occurredAtUtc(),
            'source_identity' => $this->sourceIdentity,
            'terminal_reason' => $this->terminalReason?->value,
            'to_status' => $this->toStatus->value,
        ]);
    }

    public function evidenceHash(string $eventId, int $sequenceNo): string
    {
        if (preg_match('/^[a-f0-9-]{36}$/D', $eventId) !== 1 || $sequenceNo <= 0) {
            throw new InvalidArgumentException('quality_defect_flow_evidence_identity_invalid');
        }

        return QualityDefectFlowCanonicalJson::hash([
            'event_id' => $eventId,
            'event_kind' => $this->eventKind->value,
            'from_status' => $this->fromStatus?->value,
            'occurred_at_utc' => $this->occurredAtUtc(),
            'policy_code' => $this->policy->policyCode,
            'policy_hash' => $this->policyHash(),
            'policy_version' => $this->policy->version,
            'sequence_no' => $sequenceNo,
            'source_hash' => $this->sourceHash(),
            'terminal_reason' => $this->terminalReason?->value,
            'to_status' => $this->toStatus->value,
        ]);
    }
}
