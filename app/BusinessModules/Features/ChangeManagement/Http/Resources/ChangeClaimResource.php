<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Resources;

use App\BusinessModules\Features\ChangeManagement\Models\ChangeClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ChangeClaim */
final class ChangeClaimResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var ChangeClaim $claim */
        $claim = $this->resource;

        return [
            'id' => $claim->id,
            'organization_id' => $claim->organization_id,
            'project_id' => $claim->project_id,
            'change_request_id' => $claim->change_request_id,
            'claim_number' => $claim->claim_number,
            'title' => $claim->title,
            'description' => $claim->description,
            'amount' => $claim->amount,
            'status' => $claim->status,
            'evidence' => $claim->evidence ?? [],
            'workflow_summary' => [
                'status' => $claim->status,
                'available_actions' => $claim->status === 'submitted' ? ['review', 'resolve'] : [],
            ],
            'created_at' => $claim->created_at?->toIso8601String(),
            'updated_at' => $claim->updated_at?->toIso8601String(),
        ];
    }
}
