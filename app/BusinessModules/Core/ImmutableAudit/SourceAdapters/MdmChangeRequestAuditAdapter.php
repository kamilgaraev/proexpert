<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\SourceAdapters;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventData;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditRecorder;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequestEvent;

final class MdmChangeRequestAuditAdapter
{
    public function __construct(
        private readonly ImmutableAuditRecorder $recorder,
    ) {}

    public function record(MdmChangeRequest $changeRequest, MdmChangeRequestEvent $event): void
    {
        $this->recorder->record(new ImmutableAuditEventData(
            organizationId: (int) $changeRequest->organization_id,
            domain: 'mdm',
            eventType: 'mdm.change_request.'.$event->event_type,
            action: (string) $event->event_type,
            source: 'mdm.change_requests',
            result: 'success',
            severity: $this->severity((string) $event->event_type),
            actorType: $event->actor_user_id === null ? 'system' : 'user',
            actorUserId: $event->actor_user_id === null ? null : (int) $event->actor_user_id,
            sourceModel: MdmChangeRequest::class,
            sourceTable: $event->getTable(),
            sourceEventId: (string) $event->id,
            subjectType: MdmChangeRequest::class,
            subjectId: $changeRequest->id,
            subjectLabel: $changeRequest->title,
            reason: $event->comment ?? $changeRequest->reason ?? $changeRequest->business_justification,
            beforeState: [
                'status' => $event->before_status,
                'values' => $changeRequest->current_values ?? [],
            ],
            afterState: [
                'status' => $event->after_status,
                'values' => $changeRequest->proposed_values ?? [],
                'apply_result' => $changeRequest->apply_result ?? [],
            ],
            diff: $changeRequest->diff ?? [],
            domainContext: [
                'change_request_id' => $changeRequest->id,
                'change_request_uuid' => $changeRequest->uuid,
                'entity_type' => $changeRequest->entity_type,
                'entity_id' => $changeRequest->entity_id,
                'action' => $changeRequest->action,
                'priority' => $changeRequest->priority,
                'field_policy_version' => $changeRequest->field_policy_version,
                'validation' => $changeRequest->validation_snapshot ?? [],
                'impact' => $changeRequest->impact_snapshot ?? [],
                'one_c_lock' => $changeRequest->one_c_lock_summary ?? [],
                'event_metadata' => $event->metadata ?? [],
            ],
            occurredAt: $event->created_at,
        ));
    }

    private function severity(string $eventType): string
    {
        return match ($eventType) {
            'rejected', 'cancelled', 'failed' => 'warning',
            'applied' => 'notice',
            default => 'info',
        };
    }
}
