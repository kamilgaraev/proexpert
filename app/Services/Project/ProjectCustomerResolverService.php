<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use RuntimeException;

class ProjectCustomerResolverService
{
    public function resolveOrganization(Project $project): Organization
    {
        return $this->resolve($project)['organization'];
    }

    public function resolveOrganizationId(Project $project): int
    {
        return $this->resolveOrganization($project)->id;
    }

    public function resolve(Project $project): array
    {
        $customerOrganization = $project->relationLoaded('organizations')
            ? $project->organizations->first(function (Organization $organization): bool {
                $roleValue = $organization->pivot->role_new ?? $organization->pivot->role;

                return (bool) $organization->pivot->is_active
                    && $roleValue === ProjectOrganizationRole::CUSTOMER->value;
            })
            : $project->organizations()
                ->wherePivot('is_active', true)
                ->where(function ($query): void {
                    $query
                        ->where('project_organization.role_new', ProjectOrganizationRole::CUSTOMER->value)
                        ->orWhere(function ($fallbackQuery): void {
                            $fallbackQuery
                                ->whereNull('project_organization.role_new')
                                ->where('project_organization.role', ProjectOrganizationRole::CUSTOMER->value);
                        });
                })
                ->first();

        if ($customerOrganization instanceof Organization) {
            return [
                'id' => $customerOrganization->id,
                'name' => $customerOrganization->name,
                'source' => 'project_participant',
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'is_fallback_owner' => false,
                'organization' => $customerOrganization,
            ];
        }

        $owner = $project->relationLoaded('organization')
            ? $project->organization
            : $project->organization()->first();

        if (!$owner instanceof Organization) {
            throw new RuntimeException('Customer organization for project was not resolved.');
        }

        return [
            'id' => $owner->id,
            'name' => $owner->name,
            'source' => 'project_owner',
            'role' => ProjectOrganizationRole::OWNER->value,
            'is_fallback_owner' => true,
            'organization' => $owner,
        ];
    }

    public function isResolvedCustomer(Project $project, int $organizationId): bool
    {
        return $this->resolveOrganizationId($project) === $organizationId;
    }
}
