<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\ImmutableAudit;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ImmutableAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ImmutableAuditEvent $event */
        $event = $this->resource;

        return [
            'id' => $event->id,
            'sequence_id' => $event->sequence_id,
            'domain' => $event->domain,
            'event_type' => $event->event_type,
            'action' => $event->action,
            'result' => $event->result,
            'severity' => $event->severity,
            'occurred_at' => $event->occurred_at?->toISOString(),
            'recorded_at' => $event->recorded_at?->toISOString(),
            'actor' => [
                'type' => $event->actor_type,
                'user_id' => $event->actor_user_id,
            ],
            'source' => [
                'name' => $event->source,
                'model' => $event->source_model,
                'table' => $event->source_table,
                'event_id' => $event->source_event_id,
            ],
            'subject' => [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
                'label' => $event->subject_label,
            ],
            'project_id' => $event->project_id,
            'correlation_id' => $event->correlation_id,
            'chain' => [
                'scope' => $event->chain_scope,
                'version' => $event->chain_version,
            ],
            'integrity_status' => $event->integrity_status,
            'retention_until' => $event->retention_until?->toISOString(),
            'has_sensitive_fields' => ($event->sensitive_fields ?? []) !== [],
        ];
    }
}
