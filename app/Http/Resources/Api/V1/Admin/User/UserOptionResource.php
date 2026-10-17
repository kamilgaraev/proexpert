<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\User;

use App\Models\User;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof User) {
            return [];
        }

        $rolePolicy = app(AdminUserRolePolicy::class);
        $organizationId = (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id ?? 0);
        $roles = $rolePolicy->presentOrganizationRoles($this->resource, $organizationId);
        $membership = $organizationId > 0 ? ($this->resource->relationLoaded('organizations')
            ? $this->resource->organizations->firstWhere('id', $organizationId)
            : $this->resource->organizations()->where('organizations.id', $organizationId)->first()) : null;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'position' => $this->resource->position,
            'is_active' => (bool) $this->resource->is_active && (bool) ($membership?->pivot?->is_active ?? false),
            'roles' => $roles['roles'],
            'role_labels' => $roles['role_labels'],
            'primary_role' => $roles['primary_role'],
            'primary_role_label' => $roles['primary_role_label'],
        ];
    }
}
