<?php

namespace App\Http\Resources\Api\V1\Admin\User;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForemanUserResource extends JsonResource
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

        $organizationId = (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? 0);
        $roles = $this->resolveRoles($organizationId);

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'position' => $this->resource->position,
            'avatar_path' => $this->resource->avatar_path,
            'avatar_url' => $this->resource->avatar_url,
            'is_active' => (bool) $this->resource->is_active,
            'roles' => $roles,
            'primary_role' => $this->resolvePrimaryRole($roles),
            'createdAt' => $this->resource->created_at,
            'updatedAt' => $this->resource->updated_at,
        ];
    }

    private function resolveRoles(int $organizationId): array
    {
        if ($this->resource->relationLoaded('roleAssignments')) {
            return $this->resource->roleAssignments
                ->filter(fn ($assignment) => (bool) $assignment->is_active)
                ->pluck('role_slug')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if ($organizationId > 0) {
            return array_values(array_unique($this->resource->getRoleSlugs($organizationId)));
        }

        return [];
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
