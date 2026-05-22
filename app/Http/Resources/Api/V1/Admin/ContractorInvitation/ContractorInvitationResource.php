<?php

namespace App\Http\Resources\Api\V1\Admin\ContractorInvitation;

use App\Http\Resources\ModelJsonResource;
use App\Models\ContractorInvitation;
use Illuminate\Http\Request;

class ContractorInvitationResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $invitation = $this->typedResource(ContractorInvitation::class);

        return [
            'id' => $this->id,
            'token' => $this->when($invitation->canBeAccepted(), $this->token),
            'status' => $this->status,
            'invitation_message' => $this->invitation_message,
            'expires_at' => $this->expires_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'accepted_at' => $this->accepted_at?->toISOString(),
            'is_expired' => $invitation->isExpired(),
            'can_be_accepted' => $invitation->canBeAccepted(),
            'invitation_url' => $this->when($invitation->canBeAccepted(), $invitation->getInvitationUrl()),
            
            'organization' => $this->whenLoaded('organization', function () {
                return [
                    'id' => $this->organization->id,
                    'name' => $this->organization->name,
                    'legal_name' => $this->organization->legal_name,
                    'city' => $this->organization->city,
                    'is_verified' => $this->organization->is_verified,
                ];
            }),
            
            'invited_organization' => $this->whenLoaded('invitedOrganization', function () {
                return [
                    'id' => $this->invitedOrganization->id,
                    'name' => $this->invitedOrganization->name,
                    'legal_name' => $this->invitedOrganization->legal_name,
                    'city' => $this->invitedOrganization->city,
                    'is_verified' => $this->invitedOrganization->is_verified,
                ];
            }),
            
            'invited_by' => $this->whenLoaded('invitedBy', function () {
                return [
                    'id' => $this->invitedBy->id,
                    'name' => $this->invitedBy->name,
                    'email' => $this->invitedBy->email,
                ];
            }),
            
            'accepted_by' => $this->whenLoaded('acceptedBy', function () {
                return [
                    'id' => $this->acceptedBy->id,
                    'name' => $this->acceptedBy->name,
                    'email' => $this->acceptedBy->email,
                ];
            }),
            
            'contractor' => $this->whenLoaded('contractor', function () {
                return [
                    'id' => $this->contractor->id,
                    'name' => $this->contractor->name,
                    'connected_at' => $this->contractor->connected_at?->toISOString(),
                    'last_sync_at' => $this->contractor->last_sync_at?->toISOString(),
                ];
            }),
            
            'metadata' => $this->metadata,
        ];
    }
}
