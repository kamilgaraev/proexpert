<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Illuminate\Http\Request;

final class ImmutableAuditEventDetailResource extends ImmutableAuditEventResource
{
    public function toArray(Request $request): array
    {
        /** @var ImmutableAuditEvent $event */
        $event = $this->resource;
        $data = parent::toArray($request);

        $data['actor']['snapshot'] = $event->actor_snapshot ?? [];
        $data['actor']['impersonator_user_id'] = $event->impersonator_user_id;
        $data['source']['route'] = $event->source_route;
        $data['idempotency_key'] = $event->idempotency_key;
        $data['reason'] = $event->reason;
        $data['related_subjects'] = $event->related_subjects ?? [];
        $data['before_state'] = $event->before_state ?? [];
        $data['after_state'] = $event->after_state ?? [];
        $data['diff'] = $event->diff ?? [];
        $data['domain_context'] = $event->domain_context ?? [];
        $data['redaction'] = [
            'policy_version' => $event->redaction_policy_version,
            'sensitive_fields' => $event->sensitive_fields ?? [],
        ];
        $data['integrity'] = [
            'payload_hash' => $event->payload_hash,
            'previous_hash' => $event->previous_hash,
            'record_hash' => $event->record_hash,
            'sealed_at' => $event->sealed_at?->toISOString(),
            'seal_id' => $event->seal_id,
            'status' => $event->integrity_status,
        ];

        return $data;
    }
}
