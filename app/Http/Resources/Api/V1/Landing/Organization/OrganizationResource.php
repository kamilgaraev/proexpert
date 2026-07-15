<?php

namespace App\Http\Resources\Api\V1\Landing\Organization;

use App\Http\Resources\ModelJsonResource;
use App\Models\Organization;
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

        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tax_number' => $this->tax_number,
            'registration_number' => $this->registration_number,
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
                'is_verified' => $this->is_verified,
                'verified_at' => $this->verified_at?->toISOString(),
                'verification_status' => $this->verification_status,
                'verification_status_text' => $this->verification_status_text,
                'verification_score' => $this->verification_score,
                'verification_data' => $this->verification_data,
                'verification_notes' => $this->verification_notes,
                'can_be_verified' => $organization->canBeVerified(),
            ],
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
