<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationRoleResource extends JsonResource
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
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'is_system' => $this->is_system,
            'display_order' => $this->display_order,
            'permissions' => $this->permissions ?? [],
            'permissions_formatted' => $this->formatted_permissions,
            'users_count' => $this->whenLoaded('users', function () {
                return $this->users->count();
            }),
            'users' => $this->whenLoaded('users', function () {
                return $this->users->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'assigned_at' => $user->pivot->assigned_at ?? null,
                        'assigned_by' => $user->pivot->assigned_by_user_id ?? null,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
