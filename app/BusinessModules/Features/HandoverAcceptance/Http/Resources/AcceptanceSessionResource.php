<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Resources;

use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcceptanceSession */
final class AcceptanceSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        if (!$session instanceof AcceptanceSession) {
            return [];
        }

        return [
            'id' => $session->id,
            'acceptance_scope_id' => $session->acceptance_scope_id,
            'scheduled_at' => $session->scheduled_at?->toIso8601String(),
            'started_at' => $session->started_at?->toIso8601String(),
            'completed_at' => $session->completed_at?->toIso8601String(),
            'status' => $session->status,
            'participant_user_ids' => $session->participant_user_ids ?? [],
            'summary' => $session->summary,
            'findings' => $session->relationLoaded('findings') ? AcceptanceFindingResource::collection($session->findings) : [],
        ];
    }
}
