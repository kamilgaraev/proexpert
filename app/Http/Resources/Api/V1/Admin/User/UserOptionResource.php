<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserOptionResource extends JsonResource
{
    private const ROLE_PRIORITY = [
        'organization_owner',
        'organization_admin',
        'finance_admin',
        'web_admin',
        'accountant',
        'project_manager',
        'site_engineer',
        'foreman',
        'worker',
        'observer',
        'viewer',
        'supplier',
        'contractor',
    ];

    public function toArray(Request $request): array
    {
        if (!$this->resource instanceof User) {
            return [];
        }

        $roles = $this->resolveRoles();

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'position' => $this->resource->position,
            'is_active' => (bool) $this->resource->is_active,
            'roles' => $roles,
            'primary_role' => $this->resolvePrimaryRole($roles),
        ];
    }

    private function resolveRoles(): array
    {
        if (!$this->resource->relationLoaded('roleAssignments')) {
            return [];
        }

        return $this->resource->roleAssignments
            ->filter(fn ($assignment) => (bool) $assignment->is_active)
            ->pluck('role_slug')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolvePrimaryRole(array $roles): ?string
    {
        foreach (self::ROLE_PRIORITY as $roleSlug) {
            if (in_array($roleSlug, $roles, true)) {
                return $roleSlug;
            }
        }

        return $roles[0] ?? null;
    }
}
