<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\SourceAdapters;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Payments\Models\PaymentAuditLog;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Database\Eloquent\Model;

final class PaymentAuditSourceAdapter
{
    public function __construct(
        private readonly ImmutableAuditRecorder $recorder,
    ) {}

    public function record(PaymentAuditLog $auditLog, Model $entity): void
    {
        if ($auditLog->organization_id === null) {
            return;
        }

        $document = $entity instanceof PaymentDocument ? $entity : null;
        $metadata = is_array($auditLog->metadata) ? $auditLog->metadata : [];

        $this->recorder->record(new ImmutableAuditEventData(
            organizationId: (int) $auditLog->organization_id,
            domain: 'payments',
            eventType: 'payments.'.$auditLog->action,
            action: (string) $auditLog->action,
            source: 'payments.audit_log',
            projectId: $document?->project_id,
            result: 'success',
            severity: $this->severity((string) $auditLog->action),
            actorType: $auditLog->user_id === null ? 'system' : 'user',
            actorUserId: $auditLog->user_id === null ? null : (int) $auditLog->user_id,
            actorSnapshot: [
                'name' => $auditLog->user_name,
                'role' => $auditLog->user_role,
                'ip_address' => $auditLog->ip_address,
                'user_agent' => $auditLog->user_agent,
            ],
            sourceRoute: $metadata['request_url'] ?? null,
            sourceModel: get_class($entity),
            sourceTable: $auditLog->getTable(),
            sourceEventId: (string) $auditLog->id,
            correlationId: $metadata['correlation_id'] ?? null,
            subjectType: $document instanceof PaymentDocument ? PaymentDocument::class : get_class($entity),
            subjectId: $document?->id ?? $entity->getKey(),
            subjectLabel: $document?->document_number,
            reason: $auditLog->description,
            beforeState: $auditLog->old_values ?? [],
            afterState: $auditLog->new_values ?? [],
            diff: [
                'changed_fields' => $auditLog->changed_fields ?? [],
            ],
            domainContext: [
                'payment_document_id' => $auditLog->payment_document_id,
                'entity_type' => $auditLog->entity_type,
                'entity_id' => $auditLog->entity_id,
                'metadata' => $metadata,
            ],
            occurredAt: $auditLog->created_at,
        ));
    }

    private function severity(string $action): string
    {
        return match ($action) {
            'rejected', 'cancelled', 'deleted' => 'warning',
            default => 'info',
        };
    }
}
