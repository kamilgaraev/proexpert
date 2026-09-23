<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Resources;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChangeManagementRfi */
final class ChangeRfiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChangeManagementRfi $rfi */
        $rfi = $this->resource;
        $actorOrganizationId = (int) $request->attributes->get('current_organization_id', 0);
        $status = $rfi->status === 'overdue' ? 'sent' : $rfi->status;
        $history = $rfi->relationLoaded('history') ? $rfi->history : collect();
        $safeHistory = $history->map(fn ($event): array => [
            'id' => (int) $event->id,
            'event' => $event->event,
            'actor_user_id' => $event->actor_user_id,
            'actor_organization_id' => $event->actor_organization_id,
            'actor_organization_name' => $event->actorOrganization?->name,
            'from_status' => $event->from_status === 'overdue' ? 'sent' : $event->from_status,
            'to_status' => $event->to_status === 'overdue' ? 'sent' : $event->to_status,
            'message' => $event->message,
            'attachments' => $this->safeAttachments($event->attachments ?? []),
            'created_at' => $event->created_at?->toIso8601String(),
        ])->values()->all();
        $safeAttachments = $this->safeAttachments($rfi->attachments ?? []);
        $availableActions = $this->availableActions($request, $rfi, $actorOrganizationId, $status);
        $overdue = in_array($status, ['sent', 'clarification_requested'], true)
            && $rfi->response_due_date !== null
            && $rfi->response_due_date->lt(today());

        return [
            'id' => $rfi->id,
            'organization_id' => $rfi->organization_id,
            'initiator_organization_id' => $rfi->organization_id,
            'author_organization_id' => $rfi->organization_id,
            'recipient_organization_id' => $rfi->recipient_organization_id,
            'project_id' => $rfi->project_id,
            'rfi_number' => $rfi->rfi_number,
            'subject' => $rfi->subject,
            'question' => $rfi->question,
            'addressee_type' => $rfi->addressee_type,
            'status' => $status,
            'due_date' => $rfi->response_due_date?->toDateString(),
            'is_overdue' => $overdue,
            'response_due_date' => $rfi->response_due_date?->toDateString(),
            'answer' => $rfi->answer,
            'attachments' => $safeAttachments,
            'metadata' => $rfi->metadata ?? [],
            'history' => $safeHistory,
            'available_actions' => $availableActions,
            'workflow_summary' => [
                'status' => $status,
                'available_actions' => $availableActions,
            ],
            'created_at' => $rfi->created_at?->toIso8601String(),
            'updated_at' => $rfi->updated_at?->toIso8601String(),
        ];
    }

    private function availableActions(Request $request, ChangeManagementRfi $rfi, int $organizationId, string $status): array
    {
        if ($organizationId === (int) $rfi->organization_id) {
            $actions = match ($status) {
                'draft' => ['send', 'reassign', 'upload_attachment'],
                'answered' => ['accept', 'request_clarification', 'reassign', 'upload_attachment'],
                'accepted' => ['close'],
                'sent', 'clarification_requested' => ['reassign'],
                default => [],
            };
        } elseif ($organizationId > 0 && $organizationId === (int) $rfi->recipient_organization_id) {
            $actions = in_array($status, ['sent', 'clarification_requested'], true) ? ['answer', 'upload_attachment'] : [];
        } else {
            $actions = [];
        }

        $permissionByAction = [
            'send' => 'change-management.rfi.create',
            'answer' => 'change-management.rfi.answer',
            'accept' => 'change-management.rfi.review',
            'request_clarification' => 'change-management.rfi.review',
            'close' => 'change-management.rfi.review',
            'reassign' => 'change-management.rfi.reassign',
            'upload_attachment' => 'change-management.rfi.attach',
        ];
        $user = $request->user();
        if (! $user instanceof User || $organizationId < 1) {
            return [];
        }
        $authorization = app(AuthorizationService::class);
        if ($authorization->can($user, 'change-management.rfi.reassign', ['organization_id' => $organizationId])
            && in_array($status, ['draft', 'sent', 'answered', 'clarification_requested'], true)
            && ! in_array('reassign', $actions, true)) {
            $actions[] = 'reassign';
        }

        return array_values(array_filter($actions, static fn (string $action): bool => $authorization->can(
            $user,
            $permissionByAction[$action],
            ['organization_id' => $organizationId],
        )));
    }

    private function safeAttachments(array $attachments): array
    {
        return array_values(array_map(static fn (array $attachment): array => [
            'id' => (string) ($attachment['id'] ?? ''),
            'name' => (string) ($attachment['name'] ?? ''),
            'mime_type' => $attachment['mime_type'] ?? null,
            'size' => isset($attachment['size']) ? (int) $attachment['size'] : null,
        ], array_filter($attachments, static fn ($attachment): bool => is_array($attachment)
            && isset($attachment['id'], $attachment['name'])
            && is_string($attachment['id'])
            && is_string($attachment['name']))));
    }
}
