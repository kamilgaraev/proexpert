<?php

namespace App\Http\Resources\Api\V1\Landing\ContractorInvitation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ContractorInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'token' => $this->when($this->canBeAccepted(), $this->token),
            'status' => $this->status,
            'invitation_message' => $this->invitation_message,
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'is_expired' => $this->isExpired(),
            'can_be_accepted' => $this->canBeAccepted(),
            
            'from_organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization->id,
                    'name' => $this->organization->name,
                    'legal_name' => $this->organization->legal_name,
                    'city' => $this->organization->city,
                    'is_verified' => $this->organization->is_verified,
                    'description' => $this->organization->description,
                    'logo_path' => $this->organization->logo_path,
                ];
            }),
            
            'invited_by' => $this->whenLoaded('invitedBy', function () {
                return [
                    'name' => $this->invitedBy->name,
                    'email' => $this->invitedBy->email,
                ];
            }),
            
            'accepted_by' => $this->whenLoaded('acceptedBy', function () {
                return [
                    'name' => $this->acceptedBy->name,
                    'email' => $this->acceptedBy->email,
                ];
            }),
            
            'metadata' => $this->metadata,
        ];
    }
}