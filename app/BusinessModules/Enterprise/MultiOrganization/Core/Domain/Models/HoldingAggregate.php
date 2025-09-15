<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Models;

use App\Models\Organization;
use App\Models\OrganizationGroup;
use Illuminate\Support\Collection;

class HoldingAggregate
{
    private OrganizationGroup $group;
    private Organization $parentOrganization;
    private Collection $childOrganizations;
    private array $cachedMetrics = [];

    public function __construct(OrganizationGroup $group)
    {
        $this->group = $group;
        $this->parentOrganization = $group->parentOrganization;
        $this->childOrganizations = $this->parentOrganization->childOrganizations;
    }

    public function getId(): int
    {
        return $this->group->id;
    }

    public function getName(): string
    {
        return $this->group->name;
    }

    public function getSlug(): string
    {
        return $this->group->slug;
    }

    public function getParentOrganization(): Organization
    {
        return $this->parentOrganization;
    }

    public function getChildOrganizations(): Collection
    {
        return $this->childOrganizations;
    }

    public function getAllOrganizations(): Collection
    {
        return $this->childOrganizations->push($this->parentOrganization);
    }

    public function getOrganizationCount(): int
    {
        return $this->childOrganizations->count() + 1; // +1 для родительской
    }

    public function getTotalUsersCount(): int
    {
        if (!isset($this->cachedMetrics['total_users'])) {
            $parentUsers = $this->parentOrganization->users()->count();
            $childUsers = $this->childOrganizations->sum(fn($org) => $org->users()->count());
            $this->cachedMetrics['total_users'] = $parentUsers + $childUsers;
        }

        return $this->cachedMetrics['total_users'];
    }

    public function getTotalProjectsCount(): int
    {
        if (!isset($this->cachedMetrics['total_projects'])) {
            $parentProjects = $this->parentOrganization->projects()->count();
            $childProjects = $this->childOrganizations->sum(fn($org) => $org->projects()->count());
            $this->cachedMetrics['total_projects'] = $parentProjects + $childProjects;
        }

        return $this->cachedMetrics['total_projects'];
    }

    public function getTotalContractsValue(): float
    {
        if (!isset($this->cachedMetrics['total_contracts_value'])) {
            $parentValue = $this->parentOrganization->contracts()->sum('total_amount') ?? 0;
            $childValue = $this->childOrganizations->sum(fn($org) => 
                $org->contracts()->sum('total_amount') ?? 0
            );
            $this->cachedMetrics['total_contracts_value'] = $parentValue + $childValue;
        }

        return $this->cachedMetrics['total_contracts_value'];
    }

    public function getActiveContractsCount(): int
    {
        if (!isset($this->cachedMetrics['active_contracts'])) {
            $parentActive = $this->parentOrganization->contracts()->where('status', 'active')->count();
            $childActive = $this->childOrganizations->sum(fn($org) => 
                $org->contracts()->where('status', 'active')->count()
            );
            $this->cachedMetrics['active_contracts'] = $parentActive + $childActive;
        }

        return $this->cachedMetrics['active_contracts'];
    }

    public function canAddChildOrganization(): bool
    {
        $maxOrganizations = $this->group->max_child_organizations ?? 50;
        return $this->childOrganizations->count() < $maxOrganizations;
    }

    public function getConsolidatedMetrics(): array
    {
        return [
            'organizations_count' => $this->getOrganizationCount(),
            'total_users' => $this->getTotalUsersCount(),
            'total_projects' => $this->getTotalProjectsCount(),
            'active_contracts' => $this->getActiveContractsCount(),
            'total_contracts_value' => $this->getTotalContractsValue(),
            'efficiency_metrics' => $this->calculateEfficiencyMetrics(),
        ];
    }

    private function calculateEfficiencyMetrics(): array
    {
        $totalUsers = $this->getTotalUsersCount();
        $totalProjects = $this->getTotalProjectsCount();
        $totalValue = $this->getTotalContractsValue();

        return [
            'projects_per_user' => $totalUsers > 0 ? round($totalProjects / $totalUsers, 2) : 0,
            'revenue_per_user' => $totalUsers > 0 ? round($totalValue / $totalUsers, 2) : 0,
            'avg_contract_value' => $this->getActiveContractsCount() > 0 
                ? round($totalValue / $this->getActiveContractsCount(), 2) 
                : 0,
        ];
    }

    public function clearMetricsCache(): void
    {
        $this->cachedMetrics = [];
    }
}
