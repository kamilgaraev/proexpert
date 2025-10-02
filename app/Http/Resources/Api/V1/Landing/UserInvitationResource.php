<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserInvitationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'role_slugs' => $this->role_slugs,
            'role_names' => $this->role_names,
            'status' => $this->status,
            'status_text' => $this->status_text,
            'status_color' => $this->status_color,
            'token' => $this->when(
                $request->user()?->can('users.invite', ['context_type' => 'organization', 'context_id' => $this->organization_id]),
                $this->token
            ),
            'invitation_url' => $this->when(
                $request->user()?->can('users.invite', ['context_type' => 'organization', 'context_id' => $this->organization_id]),
                $this->invitation_url
            ),
            'expires_at' => $this->expires_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'is_expired' => $this->isExpired(),
            'can_be_accepted' => $this->canBeAccepted(),
            'metadata' => $this->metadata,
            'invited_by' => $this->whenLoaded('invitedBy', function () {
                return [
                    'id' => $this->invitedBy->id,
                    'name' => $this->invitedBy->name,
                    'email' => $this->invitedBy->email,
                ];
            }),
            'accepted_by' => $this->whenLoaded('acceptedBy', function () {
                return $this->acceptedBy ? [
                    'id' => $this->acceptedBy->id,
                    'name' => $this->acceptedBy->name,
                    'email' => $this->acceptedBy->email,
                ] : null;
            }),
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization->id,
                    'name' => $this->organization->name,
                    'inn' => $this->organization->inn,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
