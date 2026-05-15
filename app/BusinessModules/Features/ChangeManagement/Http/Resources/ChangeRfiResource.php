<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Resources;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeManagementRfi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChangeManagementRfi */
final class ChangeRfiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChangeManagementRfi $rfi */
        $rfi = $this->resource;

        return [
            'id' => $rfi->id,
            'organization_id' => $rfi->organization_id,
            'project_id' => $rfi->project_id,
            'rfi_number' => $rfi->rfi_number,
            'subject' => $rfi->subject,
            'question' => $rfi->question,
            'addressee_type' => $rfi->addressee_type,
            'status' => $rfi->status,
            'response_due_date' => $rfi->response_due_date?->toDateString(),
            'answer' => $rfi->answer,
            'attachments' => $rfi->attachments ?? [],
            'metadata' => $rfi->metadata ?? [],
            'workflow_summary' => [
                'status' => $rfi->status,
                'available_actions' => $this->availableActions($rfi->status),
            ],
            'created_at' => $rfi->created_at?->toIso8601String(),
            'updated_at' => $rfi->updated_at?->toIso8601String(),
        ];
    }

    private function availableActions(string $status): array
    {
        return match ($status) {
            'draft' => ['send'],
            'sent', 'overdue', 'clarification_requested' => ['answer'],
            'answered' => ['accept', 'clarification_requested'],
            'accepted' => ['close'],
            default => [],
        };
    }
}
