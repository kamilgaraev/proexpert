<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Enums\ProjectOrganizationRole;
use App\Models\Counterparty;
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

    public function resolveLegalCustomer(Project $project): array
    {
        $project->loadMissing('customerCounterparty.linkedOrganization');

        if ($project->customerCounterparty instanceof Counterparty) {
            return [
                'id' => $project->customerCounterparty->id,
                'name' => $project->customerCounterparty->name,
                'source' => 'project_customer_counterparty',
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'is_fallback_owner' => false,
                'entity_type' => 'counterparty',
                'counterparty_id' => $project->customerCounterparty->id,
                'linked_organization_id' => $project->customerCounterparty->linked_organization_id,
                'legal_name' => $project->customerCounterparty->legal_name,
                'inn' => $project->customerCounterparty->inn,
                'kpp' => $project->customerCounterparty->kpp,
            ];
        }

        $resolved = $this->resolve($project);
        $resolved['entity_type'] = 'organization';
        $resolved['counterparty_id'] = null;
        $resolved['linked_organization_id'] = $resolved['id'];

        unset($resolved['organization']);

        return $resolved;
    }

    public function resolveCustomerCounterparty(Project $project): ?Counterparty
    {
        $project->loadMissing('customerCounterparty');

        return $project->customerCounterparty instanceof Counterparty
            ? $project->customerCounterparty
            : null;
    }

    public function isResolvedCustomer(Project $project, int $organizationId): bool
    {
        return $this->resolveOrganizationId($project) === $organizationId;
    }
}
