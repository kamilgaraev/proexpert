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
        $customerOrganization = $project->organizations()
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
            return $customerOrganization;
        }

        $owner = $project->relationLoaded('organization')
            ? $project->organization
            : $project->organization()->first();

        if (!$owner instanceof Organization) {
            throw new RuntimeException('Customer organization for project was not resolved.');
        }

        return $owner;
    }

    public function resolveOrganizationId(Project $project): int
    {
        return $this->resolveOrganization($project)->id;
    }

    public function resolve(Project $project): array
    {
        $customerOrganization = $project->organizations()
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
            'organization' => $owner,
        ];
    }

    public function isResolvedCustomer(Project $project, int $organizationId): bool
    {
        return $this->resolveOrganizationId($project) === $organizationId;
    }
}
