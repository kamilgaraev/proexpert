<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Http\Resources;

use App\BusinessModules\Features\SafetyManagement\Models\SafetyBriefing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SafetyBriefing */
final class SafetyBriefingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var SafetyBriefing $briefing */
        $briefing = $this->resource;

        return [
            'id' => $briefing->id,
            'organization_id' => $briefing->organization_id,
            'project_id' => $briefing->project_id,
            'conducted_by_user_id' => $briefing->conducted_by_user_id,
            'briefing_number' => $briefing->briefing_number,
            'title' => $briefing->title,
            'briefing_type' => $briefing->briefing_type,
            'location_name' => $briefing->location_name,
            'conducted_at' => $briefing->conducted_at?->toIso8601String(),
            'topics' => $briefing->topics ?? [],
            'notes' => $briefing->notes,
            'participants' => $this->whenLoaded('participants', fn () => $briefing->participants->map(static fn ($participant) => [
                'id' => $participant->id,
                'user_id' => $participant->user_id,
                'external_name' => $participant->external_name,
                'company_name' => $participant->company_name,
                'role_name' => $participant->role_name,
                'signed_at' => $participant->signed_at?->toIso8601String(),
                'user' => $participant->relationLoaded('user') && $participant->user ? [
                    'id' => $participant->user->id,
                    'name' => $participant->user->name,
                ] : null,
            ])->values()),
            'project' => $this->whenLoaded('project', fn () => $briefing->project ? [
                'id' => $briefing->project->id,
                'name' => $briefing->project->name,
            ] : null),
            'conducted_by_user' => $this->whenLoaded('conductedByUser', fn () => $briefing->conductedByUser ? [
                'id' => $briefing->conductedByUser->id,
                'name' => $briefing->conductedByUser->name,
            ] : null),
            'metadata' => $briefing->metadata,
            'created_at' => $briefing->created_at?->toIso8601String(),
            'updated_at' => $briefing->updated_at?->toIso8601String(),
        ];
    }
}
