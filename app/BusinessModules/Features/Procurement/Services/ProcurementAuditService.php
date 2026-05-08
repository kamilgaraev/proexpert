<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
use App\DTOs\Activity\ActivityEventData;
use App\Enums\Activity\ActivityActionEnum;
use App\Enums\Activity\ActivityResultEnum;
use App\Enums\Activity\ActivitySeverityEnum;
use App\Models\User;
use App\Services\Activity\ActivityEventRecorder;
use Illuminate\Database\Eloquent\Model;

class ProcurementAuditService
{
    public function record(
        string $eventType,
        Model $subject,
        int $organizationId,
        ?int $actorId = null,
        ?int $supplierPartyId = null,
        array $payload = []
    ): ProcurementAuditEvent {
        $type = ProcurementAuditEventTypeEnum::tryFrom($eventType);

        if ($type === null) {
            throw new \InvalidArgumentException("Unsupported procurement audit event type [{$eventType}].");
        }

        $subjectId = $subject->getKey();

        if ($subjectId === null) {
            throw new \InvalidArgumentException('Procurement audit subject must be persisted before recording an event.');
        }

        $event = ProcurementAuditEvent::query()->create([
            'organization_id' => $organizationId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subjectId,
            'event_type' => $type->value,
            'actor_id' => $actorId,
            'supplier_party_id' => $supplierPartyId,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);

        $this->recordActivityEvent($type, $subject, $organizationId, $actorId, $payload);

        return $event;
    }

    private function recordActivityEvent(
        ProcurementAuditEventTypeEnum $type,
        Model $subject,
        int $organizationId,
        ?int $actorId,
        array $payload
    ): void {
        $actor = $actorId ? User::query()->find($actorId) : null;

        app(ActivityEventRecorder::class)->record(ActivityEventData::make(
            organizationId: $organizationId,
            module: 'procurement',
            eventType: 'procurement.' . $type->value,
            action: $this->actionForEvent($type),
            actorUserId: $actor?->id,
            actorName: $actor?->name,
            actorEmail: $actor?->email,
            interface: 'admin',
            result: $this->resultForEvent($type),
            severity: $this->severityForEvent($type),
            subjectType: $subject->getMorphClass(),
            subjectId: (int) $subject->getKey(),
            subjectLabel: $this->subjectLabel($subject),
            context: $payload
        ));
    }

    private function actionForEvent(ProcurementAuditEventTypeEnum $type): ActivityActionEnum
    {
        return match ($type) {
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_APPROVED => ActivityActionEnum::Approved,
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REJECTED => ActivityActionEnum::Rejected,
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CANCELLED => ActivityActionEnum::Cancelled,
            ProcurementAuditEventTypeEnum::SUPPLIER_PROPOSAL_SELECTED => ActivityActionEnum::Assigned,
            ProcurementAuditEventTypeEnum::MATERIALS_RECEIVED => ActivityActionEnum::Updated,
            default => str_contains($type->value, 'created') ? ActivityActionEnum::Created : ActivityActionEnum::Updated,
        };
    }

    private function resultForEvent(ProcurementAuditEventTypeEnum $type): ActivityResultEnum
    {
        return $type === ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REJECTED
            ? ActivityResultEnum::Warning
            : ActivityResultEnum::Success;
    }

    private function severityForEvent(ProcurementAuditEventTypeEnum $type): ActivitySeverityEnum
    {
        return in_array($type, [
            ProcurementAuditEventTypeEnum::PROCUREMENT_APPROVAL_REJECTED,
            ProcurementAuditEventTypeEnum::SUPPLIER_REQUEST_CANCELLED,
        ], true)
            ? ActivitySeverityEnum::Warning
            : ActivitySeverityEnum::Notice;
    }

    private function subjectLabel(Model $subject): string
    {
        foreach (['number', 'document_number', 'name', 'title'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($subject) . ' #' . $subject->getKey();
    }
}
