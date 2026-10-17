<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Admin\User;

use App\Models\User;
use App\Services\User\AdminUserRolePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class ProjectTeamMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof User) {
            return [];
        }

        $organizationId = (int) ($request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? 0);
        $rolePolicy = app(AdminUserRolePolicy::class);
        $roles = $rolePolicy->presentOrganizationRoles($this->resource, $organizationId);
        $assignedProjects = $this->resolveAssignedProjects();
        $membership = $organizationId > 0 ? ($this->resource->relationLoaded('organizations')
            ? $this->resource->organizations->firstWhere('id', $organizationId)
            : $this->resource->organizations()->where('organizations.id', $organizationId)->first()) : null;

        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'position' => $this->resource->position,
            'avatar_path' => $this->resource->avatar_path,
            'avatar_url' => $this->resource->avatar_url,
            'is_active' => (bool) $this->resource->is_active && (bool) ($membership?->pivot?->is_active ?? false),
            'roles' => $roles['roles'],
            'role_labels' => $roles['role_labels'],
            'primary_role' => $roles['primary_role'],
            'primary_role_label' => $roles['primary_role_label'],
            'project_role' => $this->resource->pivot?->role,
            'project_assigned_at' => $this->resource->pivot?->assigned_at,
            'project_access_mode' => $this->resolveProjectAccessMode($organizationId),
            'project_ids' => $assignedProjects
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all(),
            'project_access' => $assignedProjects
                ->map(fn ($project): array => [
                    'id' => (int) $project->id,
                    'name' => $project->name,
                ])
                ->values()
                ->all(),
            'createdAt' => $this->resource->created_at,
            'updatedAt' => $this->resource->updated_at,
        ];
    }

    private function resolveProjectAccessMode(int $organizationId): ?string
    {
        if ($organizationId <= 0) {
            return null;
        }

        if ($this->resource->relationLoaded('organizations')) {
            $organization = $this->resource->organizations
                ->firstWhere('id', $organizationId);

            return $organization?->pivot?->project_access_mode;
        }

        return $this->resource
            ->organizations()
            ->where('organization_user.organization_id', $organizationId)
            ->value('organization_user.project_access_mode');
    }

    private function resolveAssignedProjects(): Collection
    {
        if (! $this->resource->relationLoaded('assignedProjects')) {
            return collect();
        }

        return $this->resource->assignedProjects
            ->filter(fn ($project): bool => (bool) ($project->pivot?->is_active ?? true))
            ->values();
    }
}
