<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\DTO;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Enums\ReadinessEventType;
use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessCanonicalJson;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class ReadinessEvent
{
    private function __construct(
        public string $eventId,
        public string $idempotencyKey,
        public int $organizationId,
        public int $projectId,
        public int $scheduleId,
        public int $commitmentRevisionId,
        public ?int $commitmentTaskId,
        public ReadinessEventType $eventType,
        public DateTimeImmutable $occurredAt,
        public int $actorId,
        public array $payload,
        public ?array $evidence,
        public ?string $priorEventId,
        public ReadinessPolicyDefinition $policy,
    ) {}

    public static function fromArray(array $data, ReadinessPolicyDefinition $policy): self
    {
        foreach (['organization_id', 'project_id', 'schedule_id', 'commitment_revision_id', 'actor_id'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] <= 0) {
                throw new InvalidArgumentException('lookahead_readiness_event_lineage_invalid');
            }
        }
        if ($data['organization_id'] !== $policy->organizationId
            || preg_match('/^[0-9a-f-]{36}$/D', $data['event_id'] ?? '') !== 1
            || ! is_string($data['idempotency_key'] ?? null)
            || $data['idempotency_key'] === '') {
            throw new InvalidArgumentException('lookahead_readiness_event_identity_invalid');
        }
        $type = ReadinessEventType::tryFrom($data['event_type'] ?? '');
        $occurredAt = false;
        if (isset($data['occurred_at']) && is_string($data['occurred_at'])) {
            try {
                $occurredAt = new DateTimeImmutable($data['occurred_at']);
            } catch (\Exception) {
                $occurredAt = false;
            }
        }
        if (! $type instanceof ReadinessEventType || ! $occurredAt instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('lookahead_readiness_event_invalid');
        }
        $commitmentLifecycle = in_array($type, [
            ReadinessEventType::COMMITMENT_PUBLISHED,
            ReadinessEventType::COMMITMENT_SUPERSEDED,
            ReadinessEventType::COMMITMENT_WITHDRAWN,
        ], true);
        $commitmentTaskId = $data['commitment_task_id'] ?? null;
        if (($commitmentTaskId === null) !== $commitmentLifecycle
            || ($commitmentTaskId !== null && (! is_int($commitmentTaskId) || $commitmentTaskId <= 0))) {
            throw new InvalidArgumentException('lookahead_readiness_event_task_lineage_invalid');
        }
        $payload = $data['payload'] ?? null;
        $evidence = $data['evidence'] ?? null;
        if (! is_array($payload) || ($evidence !== null && ! self::validEvidence($policy, $evidence))) {
            throw new InvalidArgumentException('lookahead_readiness_event_evidence_invalid');
        }
        if ($type === ReadinessEventType::WAIVER_APPROVED
            && ! self::validWaiverApproval($policy, $payload, $evidence, $occurredAt)) {
            throw new InvalidArgumentException('lookahead_readiness_waiver_invalid');
        }

        return new self(
            $data['event_id'],
            $data['idempotency_key'],
            $data['organization_id'],
            $data['project_id'],
            $data['schedule_id'],
            $data['commitment_revision_id'],
            $commitmentTaskId,
            $type,
            $occurredAt,
            $data['actor_id'],
            $payload,
            $evidence,
            is_string($data['prior_event_id'] ?? null) ? $data['prior_event_id'] : null,
            $policy,
        );
    }

    public function occurredAtUtc(): string
    {
        return $this->occurredAt
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }

    public function payloadHash(): string
    {
        return LookaheadReadinessCanonicalJson::hash($this->payload);
    }

    public function evidenceHash(): string
    {
        return LookaheadReadinessCanonicalJson::hash([
            'actor_id' => (string) $this->actorId,
            'commitment_revision_id' => (string) $this->commitmentRevisionId,
            'commitment_task_id' => $this->commitmentTaskId === null ? null : (string) $this->commitmentTaskId,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType->value,
            'idempotency_key' => $this->idempotencyKey,
            'organization_id' => (string) $this->organizationId,
            'occurred_at_utc' => $this->occurredAtUtc(),
            'payload_hash' => $this->payloadHash(),
            'policy_hash' => $this->policy->hash(),
            'project_id' => (string) $this->projectId,
            'schedule_id' => (string) $this->scheduleId,
            'evidence' => $this->evidence,
            'prior_event_id' => $this->priorEventId,
        ]);
    }

    private static function validEvidence(ReadinessPolicyDefinition $policy, array $evidence): bool
    {
        return in_array($evidence['type'] ?? null, $policy->evidenceTypes(), true)
            && is_string($evidence['locator'] ?? null)
            && $evidence['locator'] !== ''
            && is_string($evidence['version'] ?? null)
            && $evidence['version'] !== ''
            && preg_match('/^[a-f0-9]{64}$/D', $evidence['hash'] ?? '') === 1;
    }

    private static function validWaiverApproval(
        ReadinessPolicyDefinition $policy,
        array $payload,
        ?array $evidence,
        DateTimeImmutable $occurredAt,
    ): bool {
        $waiver = $policy->waiverPolicy();
        $validUntil = isset($payload['valid_until']) && is_string($payload['valid_until'])
            ? DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $payload['valid_until'])
            : false;

        return in_array($payload['category'] ?? null, $waiver['allowed_categories'], true)
            && is_string($payload['reason'] ?? null)
            && trim($payload['reason']) !== ''
            && ($payload['approver_permission'] ?? null) === $waiver['approver_permission']
            && preg_match('/^[a-f0-9]{64}$/D', $payload['schedule_revision_hash'] ?? '') === 1
            && $validUntil instanceof DateTimeImmutable
            && $validUntil > $occurredAt
            && $validUntil->getTimestamp() - $occurredAt->getTimestamp() <= ((int) $waiver['max_validity_hours'] * 3600)
            && $evidence !== null
            && self::validEvidence($policy, $evidence);
    }
}
