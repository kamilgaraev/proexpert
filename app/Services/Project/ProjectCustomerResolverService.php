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
        $customerParticipant = $this->resolveParticipantByRole($project, ProjectOrganizationRole::CUSTOMER);

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
        $resolved = $this->resolve($project);
        $project->loadMissing('customerCounterparty.linkedOrganization');

        if (!$resolved['is_fallback_owner']) {
            $customer = $this->fromResolvedOrganization($resolved);
            $counterparty = $project->customerCounterparty;
            if ($counterparty instanceof Counterparty
                && (int) $counterparty->organization_id === (int) $project->organization_id
                && (int) $counterparty->linked_organization_id === (int) $resolved['id']) {
                $customer['counterparty_id'] = $counterparty->id;
            }

            return $customer;
        }

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
                'legal_address' => $project->customerCounterparty->legal_address,
                'email' => $project->customerCounterparty->email,
                'phone' => $project->customerCounterparty->phone,
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

        return $this->fromResolvedOrganization($resolved);
    }

    private function fromResolvedOrganization(array $resolved): array
    {
        $organization = $resolved['organization'];
        $registrationNumber = preg_replace('/\D+/', '', (string) $organization->registration_number) ?? '';
        $resolved['entity_type'] = 'organization';
        $resolved['counterparty_id'] = null;
        $resolved['linked_organization_id'] = $resolved['id'];
        $resolved['legal_name'] = $organization->legal_name ?? $organization->name;
        $resolved['inn'] = $organization->tax_number ?? $organization->inn;
        $resolved['kpp'] = strlen($registrationNumber) === 9 ? $registrationNumber : null;
        $resolved['ogrn'] = in_array(strlen($registrationNumber), [13, 15], true) ? $registrationNumber : null;
        $resolved['legal_address'] = $organization->address;
        $resolved['email'] = $organization->email;
        $resolved['phone'] = $organization->phone;
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
            ->whereHas('organization')
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
