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
        $participant = $this->resolveParticipantByRole($project, ProjectOrganizationRole::CUSTOMER);
        if ($participant !== null) {
            $registrationNumber = preg_replace('/\D+/', '', (string) $participant->organization->registration_number) ?? '';
            return [
                'id' => $participant->organization->id,
                'name' => $participant->organization->name,
                'source' => 'project_participant',
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'is_fallback_owner' => false,
                'entity_type' => 'organization',
                'counterparty_id' => null,
                'linked_organization_id' => $participant->organization->id,
                'legal_name' => $participant->organization->legal_name ?? $participant->organization->name,
                'inn' => $participant->organization->tax_number ?? $participant->organization->inn,
                'kpp' => strlen($registrationNumber) === 9 ? $registrationNumber : null,
                'ogrn' => in_array(strlen($registrationNumber), [13, 15], true) ? $registrationNumber : null,
            ];
        }

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
                'ogrn' => $project->customerCounterparty->ogrn,
            ];
        }

        if (!empty($project->customer)) {
            return [
                'id' => null,
                'name' => trim($project->customer),
                'source' => 'project_customer_text',
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'is_fallback_owner' => false,
                'entity_type' => 'text',
                'counterparty_id' => null,
                'linked_organization_id' => null,
                'legal_name' => trim($project->customer),
                'inn' => null,
                'kpp' => null,
            ];
        }

        $resolved = $this->resolve($project);
        $resolved['entity_type'] = 'organization';
        $resolved['counterparty_id'] = null;
        $resolved['linked_organization_id'] = $resolved['id'];
        $resolved['legal_name'] = $resolved['organization']->legal_name ?? $resolved['organization']->name;
        $resolved['inn'] = $resolved['organization']->tax_number ?? $resolved['organization']->inn;
        $registrationNumber = preg_replace('/\D+/', '', (string) $resolved['organization']->registration_number) ?? '';
        $resolved['kpp'] = strlen($registrationNumber) === 9 ? $registrationNumber : null;
        $resolved['ogrn'] = in_array(strlen($registrationNumber), [13, 15], true) ? $registrationNumber : null;

        unset($resolved['organization']);

        return $resolved;
    }

    public function resolveGeneralContractor(Project $project): ?array
    {
        $participant = $this->resolveParticipantByRole($project, ProjectOrganizationRole::GENERAL_CONTRACTOR);

        if ($participant?->organization instanceof Organization) {
            return [
                'id' => $participant->organization->id,
                'name' => $participant->organization->name,
                'legal_name' => $participant->organization->legal_name ?? $participant->organization->name,
                'inn' => $participant->organization->tax_number,
                'entity_type' => 'organization',
                'role' => ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
            ];
        }

        return null;
    }

    public function resolveDesigner(Project $project): ?array
    {
        $participant = $this->resolveParticipantByRole($project, ProjectOrganizationRole::DESIGNER);

        if ($participant?->organization instanceof Organization) {
            return [
                'id' => $participant->organization->id,
                'name' => $participant->organization->name,
                'legal_name' => $participant->organization->legal_name ?? $participant->organization->name,
                'inn' => $participant->organization->tax_number,
                'entity_type' => 'organization',
                'role' => ProjectOrganizationRole::DESIGNER->value,
            ];
        }

        if (!empty($project->designer)) {
            return [
                'id' => null,
                'name' => trim($project->designer),
                'legal_name' => trim($project->designer),
                'inn' => null,
                'entity_type' => 'text',
                'role' => ProjectOrganizationRole::DESIGNER->value,
            ];
        }

        return null;
    }

    public function resolveConstructionSupervision(Project $project): ?array
    {
        $participant = $this->resolveParticipantByRole($project, ProjectOrganizationRole::CONSTRUCTION_SUPERVISION);

        if ($participant?->organization instanceof Organization) {
            return [
                'id' => $participant->organization->id,
                'name' => $participant->organization->name,
                'legal_name' => $participant->organization->legal_name ?? $participant->organization->name,
                'inn' => $participant->organization->tax_number,
                'entity_type' => 'organization',
                'role' => ProjectOrganizationRole::CONSTRUCTION_SUPERVISION->value,
            ];
        }

        return null;
    }

    private function resolveParticipantByRole(Project $project, ProjectOrganizationRole $role): ?ProjectOrganization
    {
        return ProjectOrganization::query()
            ->useWritePdo()
            ->with('organization')
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->where(function ($query) use ($role): void {
                $query
                    ->where('role_new', $role->value)
                    ->orWhere(function ($fallbackQuery) use ($role): void {
                        $fallbackQuery
                            ->whereNull('role_new')
                            ->where('role', $role->value);
                    });
            })
            ->orderByDesc('id')
            ->first();
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
