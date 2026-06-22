<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AccessRecertification\Services;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRedactor;

final class AccessRecertificationEvidenceBuilder
{
    public function __construct(
        private readonly ImmutableAuditRedactor $redactor = new ImmutableAuditRedactor(),
    ) {}

    public function assignmentSnapshot(array $assignment): array
    {
        return $this->redactor->redact([
            'assignment_id' => $assignment['assignment_id'] ?? null,
            'user_id' => $assignment['user_id'] ?? null,
            'user_name' => isset($assignment['user_name']) ? ImmutableAuditRedactor::REDACTED : null,
            'user_email' => isset($assignment['user_email']) ? ImmutableAuditRedactor::REDACTED : null,
            'role_slug' => $assignment['role_slug'] ?? null,
            'role_type' => $assignment['role_type'] ?? 'system',
            'role_label' => $assignment['role_label'] ?? $assignment['role_slug'] ?? null,
            'context_type' => $assignment['context_type'] ?? null,
            'context_resource_id' => $assignment['context_resource_id'] ?? null,
            'permissions' => array_values(array_unique(array_filter($assignment['permissions'] ?? []))),
            'risk' => $assignment['risk'] ?? [],
            'captured_at' => now()->toISOString(),
        ]);
    }

    public function publicItemEvidence(array $snapshot): array
    {
        return [
            'assignment_id' => $snapshot['assignment_id'] ?? null,
            'role_slug' => $snapshot['role_slug'] ?? null,
            'role_label' => $snapshot['role_label'] ?? null,
            'role_type' => $snapshot['role_type'] ?? null,
            'context_type' => $snapshot['context_type'] ?? null,
            'context_resource_id' => $snapshot['context_resource_id'] ?? null,
            'permissions_count' => count($snapshot['permissions'] ?? []),
            'risk' => $snapshot['risk'] ?? [],
            'captured_at' => $snapshot['captured_at'] ?? null,
        ];
    }

    public function auditEventData(
        int $organizationId,
        ?int $actorUserId,
        string $eventType,
        string $action,
        string $subjectType,
        int|string $subjectId,
        ?string $subjectLabel,
        ?string $correlationId,
        string $sourceEventId,
        ?string $reason = null,
        array $beforeState = [],
        array $afterState = [],
        array $diff = [],
        array $domainContext = [],
        string $severity = 'info',
        string $result = 'success',
    ): ImmutableAuditEventData {
        return new ImmutableAuditEventData(
            organizationId: $organizationId,
            domain: 'rbac',
            eventType: $eventType,
            action: $action,
            source: 'access_recertification',
            result: $result,
            severity: $severity,
            actorType: $actorUserId === null ? 'system' : 'user',
            actorUserId: $actorUserId,
            sourceEventId: $sourceEventId,
            correlationId: $correlationId,
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $subjectLabel,
            reason: $reason,
            beforeState: $this->redactor->redact($beforeState),
            afterState: $this->redactor->redact($afterState),
            diff: $this->redactor->redact($diff),
            domainContext: $this->redactor->redact($domainContext),
            chainScope: 'organization:' . $organizationId . ':rbac',
        );
    }
}
