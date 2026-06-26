<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\ErpControls;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

final class ErpControlConflictResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ImmutableAuditEvent $event */
        $event = $this->resource;
        $context = $event->domain_context ?? [];

        return [
            'id' => $event->id,
            'status' => 'open',
            'domain' => $context['domain'] ?? null,
            'risk_level' => $event->severity,
            'operation' => $event->action,
            'actor' => [
                'id' => $event->actor_user_id,
                'name' => $event->actor_snapshot['name'] ?? null,
            ],
            'object_label' => $event->subject_label,
            'message' => $context['message'] ?? trans_message('erp_controls.messages.operation_blocked'),
            'blockers' => $context['blockers'] ?? [],
            'warnings' => $context['warnings'] ?? [],
            'created_at' => $event->occurred_at?->toISOString(),
            'available_actions' => $this->availableActions((string) $event->severity),
        ];
    }

    private function availableActions(string $riskLevel): array
    {
        return $riskLevel === 'critical'
            ? ['request_review', 'accepted_risk']
            : ['acknowledged', 'false_positive', 'accepted_risk'];
    }
}
