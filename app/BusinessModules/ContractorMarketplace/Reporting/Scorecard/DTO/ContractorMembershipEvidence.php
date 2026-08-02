<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;

final readonly class ContractorMembershipEvidence
{
    public string $sourceHash;

    public function __construct(
        public array $profileByContractor,
        public array $profileBySupplier,
        public array $profileOrganizationById,
        public array $categoriesByProfile,
        public array $evidence,
        public string $coverageStartedAt,
    ) {
        $this->sourceHash = hash('sha256', CanonicalJson::encode([
            'categories_by_profile' => $this->categoriesByProfile,
            'coverage_started_at' => $this->coverageStartedAt,
            'evidence' => $this->evidence,
            'profile_by_contractor' => $this->profileByContractor,
            'profile_by_supplier' => $this->profileBySupplier,
            'profile_organization_by_id' => $this->profileOrganizationById,
        ]));
    }
}
