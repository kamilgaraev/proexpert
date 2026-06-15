<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Http\Resources;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

use function trans_message;

final class CommercialProposalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $canViewAmounts = $this->canViewAmounts($request);
        $status = $this->status instanceof CommercialProposalStatus
            ? $this->status
            : CommercialProposalStatus::from((string) $this->status);
        $actionDetails = $this->actionDetails($request, $status);
        $nextAction = collect($actionDetails)->first(static fn (array $action): bool => $action['enabled'] === true);

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'number' => $this->number,
            'proposal_number' => $this->number,
            'title' => $this->title,
            'status' => $status->value,
            'status_label' => trans_message($status->labelKey()),
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'customer' => [
                'name' => $this->customer_name,
                'email' => $this->customer_email,
                'phone' => $this->customer_phone,
            ],
            'amounts_visible' => $canViewAmounts,
            'amount_visible' => $canViewAmounts,
            'amounts' => $canViewAmounts ? [
                'subtotal_amount' => $this->subtotal_amount,
                'discount_amount' => $this->discount_amount,
                'vat_amount' => $this->vat_amount,
                'total_amount' => $this->total_amount,
                'currency' => $this->currency,
            ] : null,
            'total_amount' => $canViewAmounts ? $this->total_amount : null,
            'currency' => $this->currency,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'links' => [
                'crm_deal_id' => $this->crm_deal_id,
                'tender_id' => $this->tender_id,
                'presale_estimate_id' => $this->presale_estimate_id,
                'project_id' => $this->project_id,
                'contract_id' => $this->contract_id,
                'crm_deal' => $this->relationLoaded('crmDeal') && $this->crmDeal !== null ? [
                    'id' => $this->crmDeal->id,
                    'title' => $this->crmDeal->title,
                    'number' => $this->crmDeal->id,
                    'label' => $this->crmDeal->title,
                ] : null,
            ],
            'workflow_summary' => [
                'stage' => $status->value,
                'status' => $status->value,
                'stage_label' => trans_message($status->labelKey()),
                'status_label' => trans_message($status->labelKey()),
                'next_action' => $nextAction['action'] ?? null,
                'available_actions' => $status->availableActions(),
                'available_action_details' => $actionDetails,
                'blockers' => [],
                'problem_flags' => [],
                'warnings' => [],
                'meta' => [
                    'current_version_id' => $this->current_version_id,
                    'accepted_version_id' => $this->accepted_version_id,
                ],
            ],
            'metadata' => $this->metadata ?? [],
            'current_version_id' => $this->current_version_id,
            'accepted_version_id' => $this->accepted_version_id,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion === null ? null : new CommercialProposalVersionResource($this->currentVersion)),
            'accepted_version' => $this->whenLoaded('acceptedVersion', fn () => $this->acceptedVersion === null ? null : new CommercialProposalVersionResource($this->acceptedVersion)),
            'versions' => $this->whenLoaded('versions', fn () => CommercialProposalVersionResource::collection($this->versions)->resolve($request)),
            'files' => $this->whenLoaded('files', fn () => CommercialProposalFileResource::collection($this->files)->resolve($request)),
            'timeline' => $this->whenLoaded('timelineEvents', fn () => CommercialProposalTimelineEventResource::collection($this->timelineEvents)->resolve($request)),
            'sent_events' => $this->whenLoaded('sentEvents', fn (): array => $this->sentEvents->map(static fn ($event): array => [
                'id' => $event->id,
                'channel' => $event->channel,
                'recipient' => $event->recipient,
                'subject' => $event->subject,
                'sent_at' => $event->sent_at?->toIso8601String(),
            ])->values()->all()),
            'approvals' => $this->whenLoaded('approvals', fn (): array => $this->approvals->map(static fn ($approval): array => [
                'id' => $approval->id,
                'commercial_proposal_version_id' => $approval->commercial_proposal_version_id,
                'status' => $approval->status,
                'comment' => $approval->comment,
                'requested_at' => $approval->requested_at?->toIso8601String(),
                'decided_at' => $approval->decided_at?->toIso8601String(),
            ])->values()->all()),
            'exports' => $this->whenLoaded('exports', fn () => CommercialProposalExportResource::collection($this->exports)->resolve($request)),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'customer_decision_at' => $this->customer_decision_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'is_archived' => $this->deleted_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{action:string,label:string,permission:string,enabled:bool,blockers:array<int, mixed>}>
     */
    private function actionDetails(Request $request, CommercialProposalStatus $status): array
    {
        return array_map(function (string $action) use ($request): array {
            $permission = match ($action) {
                'update' => 'commercial_proposals.update',
                'create_version' => 'commercial_proposals.versions.create',
                'request_approval' => 'commercial_proposals.approval.request',
                'approve', 'reject' => 'commercial_proposals.approval.decide',
                'send' => 'commercial_proposals.send',
                'record_result' => 'commercial_proposals.result',
                'export' => 'commercial_proposals.export',
                'archive' => 'commercial_proposals.archive',
                default => 'commercial_proposals.view',
            };

            return [
                'action' => $action,
                'label' => trans_message("commercial_proposals.actions.{$action}"),
                'permission' => $permission,
                'enabled' => $this->canUsePermission($request, $permission),
                'blockers' => [],
            ];
        }, $status->availableActions());
    }

    private function canUsePermission(Request $request, string $permission): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $organizationId = (int) $request->attributes->get('current_organization_id');

        return $organizationId > 0 && $user->can($permission, [
            'organization_id' => $organizationId,
        ]);
    }

    private function canViewAmounts(Request $request): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $organizationId = (int) $request->attributes->get('current_organization_id');

        return $organizationId > 0 && $user->can('commercial_proposals.amounts.view', [
            'organization_id' => $organizationId,
        ]);
    }
}
