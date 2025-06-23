<?php

namespace App\Services\Landing;

use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\OrganizationAccessPermission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MultiOrganizationService
{
    public function createOrganizationGroup(User $user, array $data): OrganizationGroup
    {
        $organization = $user->currentOrganization;
        
        if (!$organization) {
            throw new \Exception('Организация не найдена');
        }

        if ($organization->is_holding ?? false) {
            throw new \Exception('Организация уже является холдингом');
        }

        return DB::transaction(function () use ($organization, $user, $data) {
            $organization->update([
                'organization_type' => 'parent',
                'is_holding' => true,
                'hierarchy_level' => 0,
                'hierarchy_path' => (string) $organization->id,
                'multi_org_settings' => $data['settings'] ?? []
            ]);

            $group = OrganizationGroup::create([
                'name' => $data['name'],
                'slug' => Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'parent_organization_id' => $organization->id,
                'created_by_user_id' => $user->id,
                'max_child_organizations' => $data['max_child_organizations'] ?? 10,
                'settings' => $data['settings'] ?? [],
                'permissions_config' => $data['permissions_config'] ?? $this->getDefaultPermissionsConfig(),
            ]);

            return $group;
        });
    }

    public function addChildOrganization(OrganizationGroup $group, array $organizationData, User $creator): Organization
    {
        if (!$group->canAddChildOrganization()) {
            throw new \Exception('Достигнут лимит дочерних организаций');
        }

        return DB::transaction(function () use ($group, $organizationData, $creator) {
            $parentOrg = $group->parentOrganization;
            
            $childOrg = Organization::create([
                'name' => $organizationData['name'],
                'description' => $organizationData['description'] ?? null,
                'parent_organization_id' => $parentOrg->id,
                'organization_type' => 'child',
                'is_holding' => false,
                'hierarchy_level' => 1,
                'hierarchy_path' => $parentOrg->hierarchy_path . '.' . $parentOrg->id,
                'tax_number' => $organizationData['inn'] ?? null,
                'registration_number' => $organizationData['kpp'] ?? null,
                'address' => $organizationData['address'] ?? null,
                'phone' => $organizationData['phone'] ?? null,
                'email' => $organizationData['email'] ?? null,
            ]);

            $this->createDefaultAccessPermissions($parentOrg, $childOrg, $creator);

            $childOrg->users()->attach($creator->id, [
                'is_owner' => true,
                'is_active' => true,
            ]);

            return $childOrg;
        });
    }

    public function getOrganizationHierarchy(int $organizationId): array
    {
        $organization = Organization::with(['childOrganizations', 'parentOrganization'])->findOrFail($organizationId);
        
        if ($organization->organization_type === 'child') {
            $organization = $organization->parentOrganization;
        }

        return [
            'parent' => $this->formatOrganizationData($organization),
            'children' => $organization->childOrganizations->map(function ($child) {
                return $this->formatOrganizationData($child);
            })->toArray(),
            'total_stats' => $this->getHierarchyStats($organization),
        ];
    }

    public function getAccessibleOrganizations(User $user): Collection
    {
        $userOrgId = $user->current_organization_id;
        $userOrg = Organization::find($userOrgId);

        if (!$userOrg) {
            return collect([]);
        }

        if ($userOrg->is_holding ?? false) {
            return Organization::where('parent_organization_id', $userOrgId)
                ->orWhere('id', $userOrgId)
                ->get();
        }

        if ($userOrg->parent_organization_id) {
            return collect([$userOrg]);
        }

        return collect([$userOrg]);
    }

    public function hasAccessToOrganization(User $user, int $targetOrgId, string $permission = 'read'): bool
    {
        $userOrgId = $user->current_organization_id;
        
        if ($userOrgId === $targetOrgId) {
            return true;
        }

        $userOrg = Organization::find($userOrgId);
        $targetOrg = Organization::find($targetOrgId);

        if (!$userOrg || !$targetOrg) {
            return false;
        }

        if (($userOrg->is_holding ?? false) && $targetOrg->parent_organization_id === $userOrgId) {
            return true;
        }

        return OrganizationAccessPermission::where('granted_to_organization_id', $userOrgId)
            ->where('target_organization_id', $targetOrgId)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public function getOrganizationData(int $organizationId, User $user): array
    {
        if (!$this->hasAccessToOrganization($user, $organizationId)) {
            throw new \Exception('Нет доступа к данным организации');
        }

        $organization = Organization::with([
            'users',
            'projects',
            'contracts'
        ])->findOrFail($organizationId);

        return [
            'organization' => $this->formatOrganizationData($organization),
            'stats' => [
                'users_count' => $organization->users()->count(),
                'projects_count' => $organization->projects()->count(),
                'contracts_count' => $organization->contracts()->count(),
                'active_contracts_value' => $organization->contracts()
                    ->where('status', 'active')
                    ->sum('total_amount'),
            ],
            'recent_activity' => $this->getRecentActivity($organization),
        ];
    }

    private function createDefaultAccessPermissions(Organization $parentOrg, Organization $childOrg, User $creator): void
    {
        $defaultPermissions = [
            'projects' => ['read', 'create', 'edit', 'delete'],
            'contracts' => ['read', 'create', 'edit'],
            'materials' => ['read', 'create', 'edit'],
            'reports' => ['read', 'export'],
            'users' => ['read'],
        ];

        foreach ($defaultPermissions as $resourceType => $permissions) {
            OrganizationAccessPermission::create([
                'granted_to_organization_id' => $parentOrg->id,
                'target_organization_id' => $childOrg->id,
                'resource_type' => $resourceType,
                'permissions' => $permissions,
                'access_level' => 'admin',
                'granted_by_user_id' => $creator->id,
            ]);
        }
    }

    private function getDefaultPermissionsConfig(): array
    {
        return [
            'default_child_permissions' => [
                'projects' => ['read', 'create', 'edit'],
                'contracts' => ['read', 'create'],
                'materials' => ['read', 'create'],
                'reports' => ['read'],
                'users' => ['read'],
            ],
            'parent_permissions' => [
                'projects' => ['read', 'create', 'edit', 'delete'],
                'contracts' => ['read', 'create', 'edit', 'delete'],
                'materials' => ['read', 'create', 'edit', 'delete'],
                'reports' => ['read', 'export', 'admin'],
                'users' => ['read', 'create', 'edit'],
            ],
        ];
    }

    private function formatOrganizationData(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'organization_type' => $organization->organization_type ?? 'single',
            'is_holding' => $organization->is_holding ?? false,
            'hierarchy_level' => $organization->hierarchy_level ?? 0,
            'tax_number' => $organization->tax_number,
            'registration_number' => $organization->registration_number,
            'address' => $organization->address,
            'created_at' => $organization->created_at,
        ];
    }

    private function getHierarchyStats(Organization $parentOrg): array
    {
        $childOrgs = $parentOrg->childOrganizations;
        
        return [
            'total_organizations' => 1 + $childOrgs->count(),
            'total_users' => $parentOrg->users()->count() + $childOrgs->sum(fn($org) => $org->users()->count()),
            'total_projects' => $parentOrg->projects()->count() + $childOrgs->sum(fn($org) => $org->projects()->count()),
            'total_contracts' => $parentOrg->contracts()->count() + $childOrgs->sum(fn($org) => $org->contracts()->count()),
        ];
    }

    private function getRecentActivity(Organization $organization): array
    {
        return [
            'last_project_created' => $organization->projects()->latest()->first()?->created_at,
            'last_contract_signed' => $organization->contracts()->latest()->first()?->created_at,
            'last_user_added' => $organization->users()->latest('pivot_created_at')->first()?->pivot?->created_at,
        ];
    }
} 