<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementAuditEvent;
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

        return ProcurementAuditEvent::query()->create([
            'organization_id' => $organizationId,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => (int) $subjectId,
            'event_type' => $type->value,
            'actor_id' => $actorId,
            'supplier_party_id' => $supplierPartyId,
            'occurred_at' => now(),
            'payload' => $payload,
        ]);
    }
}
