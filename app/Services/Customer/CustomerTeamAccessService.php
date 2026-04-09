<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Exceptions\BusinessLogicException;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as FileSystem;

class CustomerTeamAccessService
{
    public function getUserAccess(User $targetUser, Request $request): array
    {
        $organizationId = $this->resolveOrganizationId($request);
        $member = $this->resolveOrganizationMember($targetUser, $organizationId);
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        return [
            'member' => $this->buildMemberPayload($member, $organizationId, $context),
            'available_roles' => $this->loadCustomerRoleCatalog(),
            'available_projects' => $this->loadAvailableProjects($organizationId),
        ];
    }

    public function updateUserAccess(User $targetUser, Request $request, array $payload): array
    {
        $organizationId = $this->resolveOrganizationId($request);
        $actor = $request->user();
        $member = $this->resolveOrganizationMember($targetUser, $organizationId);
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        DB::transaction(function () use ($member, $organizationId, $actor, $context, $payload): void {
            $this->syncCustomerRoles($member, $context, $actor, $payload);
            $this->syncProjectAccess($member, $organizationId, $payload);
        });

        return [
            'member' => $this->buildMemberPayload($member->fresh(['organizations']), $organizationId, $context),
            'available_roles' => $this->loadCustomerRoleCatalog(),
            'available_projects' => $this->loadAvailableProjects($organizationId),
        ];
    }

    private function syncCustomerRoles(User $member, AuthorizationContext $context, ?User $actor, array $payload): void
    {
        $allowedRoleSlugs = collect($this->loadCustomerRoleCatalog())
            ->pluck('slug')
            ->filter(fn ($slug) => is_string($slug) && str_starts_with($slug, 'customer_'))
            ->values()
            ->all();

        $assignments = $member->roleAssignments()
            ->where('context_id', $context->id)
            ->whereIn('role_slug', $allowedRoleSlugs)
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->update(['is_active' => false]);
        }

        if (!($payload['is_active'] ?? true)) {
            return;
        }

        $roleSlug = $payload['role_slug'] ?? null;

        if (!is_string($roleSlug) || !in_array($roleSlug, $allowedRoleSlugs, true)) {
            throw new BusinessLogicException('Роль customer-доступа не выбрана или недоступна.', 422);
        }

        UserRoleAssignment::assignRole(
            $member,
            $roleSlug,
            $context,
            UserRoleAssignment::TYPE_SYSTEM,
            $actor
        );
    }

    private function syncProjectAccess(User $member, int $organizationId, array $payload): void
    {
        $allowedProjectIds = collect($this->loadAvailableProjects($organizationId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $projectIds = collect($payload['project_ids'] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => in_array($id, $allowedProjectIds, true))
            ->values()
            ->all();

        $member->assignedProjects()
            ->whereIn('projects.id', $allowedProjectIds)
            ->detach();

        if (!empty($projectIds) && ($payload['is_active'] ?? true)) {
            $syncPayload = [];
            foreach ($projectIds as $projectId) {
                $syncPayload[$projectId] = ['role' => 'customer_member'];
            }

            $member->assignedProjects()->syncWithoutDetaching($syncPayload);
        }
    }

    private function buildMemberPayload(User $member, int $organizationId, AuthorizationContext $context): array
    {
        $availableProjectIds = collect($this->loadAvailableProjects($organizationId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        $assignedProjects = $member->assignedProjects()
            ->whereIn('projects.id', $availableProjectIds)
            ->get(['projects.id', 'projects.name']);
        $activeRoles = $member->roleAssignments()
            ->where('context_id', $context->id)
            ->where('is_active', true)
            ->where('role_slug', 'like', 'customer_%')
            ->latest('updated_at')
            ->get();

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'status' => $activeRoles->isNotEmpty() ? 'active' : 'inactive',
            'role_slug' => $activeRoles->first()?->role_slug,
            'roles' => $activeRoles->pluck('role_slug')->values()->all(),
            'project_ids' => $assignedProjects->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'project_access' => $assignedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])->all(),
            'has_full_project_access' => $assignedProjects->isEmpty(),
            'access_history' => $member->roleAssignments()
                ->where('context_id', $context->id)
                ->where('role_slug', 'like', 'customer_%')
                ->latest('updated_at')
                ->limit(10)
                ->get()
                ->map(fn (UserRoleAssignment $assignment): array => [
                    'id' => $assignment->id,
                    'role_slug' => $assignment->role_slug,
                    'is_active' => (bool) $assignment->is_active,
                    'updated_at' => $assignment->updated_at?->toISOString(),
                ])
                ->all(),
        ];
    }

    private function loadAvailableProjects(int $organizationId): array
    {
        return Project::query()
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get(['projects.id', 'projects.name'])
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])
            ->all();
    }

    private function loadCustomerRoleCatalog(): array
    {
        $path = config_path('RoleDefinitions/customer');

        if (!FileSystem::exists($path)) {
            return [];
        }

        return collect(FileSystem::files($path))
            ->map(function ($file): ?array {
                $decoded = json_decode(FileSystem::get($file->getPathname()), true);

                if (!is_array($decoded) || !isset($decoded['slug'], $decoded['name'])) {
                    return null;
                }

                return [
                    'slug' => $decoded['slug'],
                    'name' => $decoded['name'],
                    'description' => $decoded['description'] ?? null,
                    'permissions' => $decoded['system_permissions'] ?? [],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveOrganizationMember(User $targetUser, int $organizationId): User
    {
        $member = User::query()
            ->whereKey($targetUser->id)
            ->whereHas('organizations', fn ($builder) => $builder->where('organization_user.organization_id', $organizationId))
            ->first();

        if (!$member) {
            throw new BusinessLogicException('Пользователь не найден в текущей организации.', 404);
        }

        return $member;
    }

    private function resolveOrganizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        if (!$organizationId) {
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }

        return (int) $organizationId;
    }
}
