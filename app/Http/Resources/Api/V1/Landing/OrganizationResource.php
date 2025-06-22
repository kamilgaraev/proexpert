<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Organization
 */
class OrganizationResource extends JsonResource
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
            'subscription_expires_at' => $this->subscription_expires_at?->toISOString(),
            
            'verification' => [
                'is_verified' => $this->is_verified ?? false,
                'verified_at' => $this->verified_at?->toISOString(),
                'verification_status' => $this->verification_status ?? 'pending',
                'verification_status_text' => $this->verification_status_text ?? 'Ожидает верификации',
                'verification_score' => $this->verification_score ?? 0,
                'verification_data' => $this->verification_data,
                'verification_notes' => $this->verification_notes,
                'can_be_verified' => method_exists($this->resource, 'canBeVerified') ? $this->canBeVerified() : false,
            ],
            
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
} 