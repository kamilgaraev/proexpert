<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\ErpControls;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ErpControlAuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ImmutableAuditEvent $event */
        $event = $this->resource;
        $context = $event->domain_context ?? [];

        return [
            'id' => $event->id,
            'operation' => $event->action,
            'domain' => $context['domain'] ?? null,
            'risk_level' => $event->severity,
            'decision' => $event->result,
            'message' => $context['message'] ?? null,
            'actor' => [
                'type' => $event->actor_type,
                'user_id' => $event->actor_user_id,
            ],
            'subject' => [
                'type' => $event->subject_type,
                'id' => $event->subject_id,
                'label' => $event->subject_label,
            ],
            'blockers' => $context['blockers'] ?? [],
            'warnings' => $context['warnings'] ?? [],
            'required_actions' => $context['required_actions'] ?? [],
            'override_available' => (bool) ($context['override_available'] ?? false),
            'reason' => $event->reason,
            'occurred_at' => $event->occurred_at?->toISOString(),
        ];
    }
}
