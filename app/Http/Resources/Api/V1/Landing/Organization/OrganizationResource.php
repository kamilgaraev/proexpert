<?php

namespace App\Http\Resources\Api\V1\Landing\Organization;

use App\Http\Resources\ModelJsonResource;
use App\Models\Organization;
use App\Services\OrganizationVerificationService;
use Illuminate\Http\Request;

class OrganizationResource extends ModelJsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $organization = $this->typedResource(Organization::class);
        $verification = app(OrganizationVerificationService::class)->getVerificationRecommendations($organization);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_number' => $this->tax_number,
            'registration_number' => $this->registration_number,
            'okpo' => $this->okpo,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'description' => $this->description,
            'logo_path' => $this->logo_path,
            'is_active' => $this->is_active,

            'verification' => [
                'is_verified' => $verification['status'] === 'verified',
                'verified_at' => $verification['status'] === 'verified' ? $this->verified_at?->toISOString() : null,
                'verification_status' => $verification['status'],
                'verification_status_text' => $verification['status_text'],
                'verification_score' => $verification['current_score'],
                'verification_data' => $this->verification_data,
                'verification_notes' => $this->verification_notes,
                'can_be_verified' => $organization->canBeVerified(),
            ],

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
