<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;
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
        $customerParticipant = ProjectOrganization::query()
            ->useWritePdo()
            ->with('organization')
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->where('role_new', ProjectOrganizationRole::CUSTOMER->value)
                    ->orWhere(function ($fallbackQuery): void {
                        $fallbackQuery
                            ->whereNull('role_new')
                            ->where('role', ProjectOrganizationRole::CUSTOMER->value);
                    });
            })
            ->orderByDesc('id')
            ->first();

        $customerOrganization = $customerParticipant?->organization;

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

        $owner = Organization::query()
            ->useWritePdo()
            ->find($project->organization_id);

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
