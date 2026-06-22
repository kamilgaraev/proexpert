<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\DTO;

use Illuminate\Support\Carbon;

final class ImmutableAuditEventData
{
    public function __construct(
        public readonly int $organizationId,
        public readonly string $domain,
        public readonly string $eventType,
        public readonly string $action,
        public readonly string $source,
        public readonly ?int $projectId = null,
        public readonly string $result = 'success',
        public readonly string $severity = 'info',
        public readonly string $actorType = 'system',
        public readonly ?int $actorUserId = null,
        public readonly array $actorSnapshot = [],
        public readonly ?int $impersonatorUserId = null,
        public readonly ?string $sourceRoute = null,
        public readonly ?string $sourceModel = null,
        public readonly ?string $sourceTable = null,
        public readonly ?string $sourceEventId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $idempotencyKey = null,
        public readonly ?string $subjectType = null,
        public readonly int|string|null $subjectId = null,
        public readonly ?string $subjectLabel = null,
        public readonly array $relatedSubjects = [],
        public readonly ?string $reason = null,
        public readonly array $beforeState = [],
        public readonly array $afterState = [],
        public readonly array $diff = [],
        public readonly array $domainContext = [],
        public readonly ?string $chainScope = null,
        public readonly int $chainVersion = 1,
        public readonly ?Carbon $occurredAt = null,
    ) {}

    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'project_id' => $this->projectId,
            'domain' => $this->domain,
            'event_type' => $this->eventType,
            'action' => $this->action,
            'result' => $this->result,
            'severity' => $this->severity,
            'actor_type' => $this->actorType,
            'actor_user_id' => $this->actorUserId,
            'actor_snapshot' => $this->actorSnapshot,
            'impersonator_user_id' => $this->impersonatorUserId,
            'source' => $this->source,
            'source_route' => $this->sourceRoute,
            'source_model' => $this->sourceModel,
            'source_table' => $this->sourceTable,
            'source_event_id' => $this->sourceEventId,
            'correlation_id' => $this->correlationId,
            'idempotency_key' => $this->idempotencyKey,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId === null ? null : (string) $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'related_subjects' => $this->relatedSubjects,
            'reason' => $this->reason,
            'before_state' => $this->beforeState,
            'after_state' => $this->afterState,
            'diff' => $this->diff,
            'domain_context' => $this->domainContext,
            'chain_scope' => $this->chainScope,
            'chain_version' => $this->chainVersion,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
