<?php

namespace App\BusinessModules\Enterprise\MultiOrganization\Core\Domain\Events;

use App\Models\Organization;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrganizationDataUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Organization $organization,
        public array $changedFields = [],
        public ?int $updatedBy = null
    ) {}

    public function getHoldingId(): ?int
    {
        if ($this->organization->parent_organization_id) {
            return $this->organization->parentOrganization->organizationGroup?->id;
        }
        
        return $this->organization->organizationGroup?->id;
    }

    public function isFinancialDataChanged(): bool
    {
        $financialFields = ['balance', 'contracts', 'projects'];
        return !empty(array_intersect($this->changedFields, $financialFields));
    }

    public function isStructuralChange(): bool
    {
        $structuralFields = ['parent_organization_id', 'organization_type', 'is_holding'];
        return !empty(array_intersect($this->changedFields, $structuralFields));
    }
}
