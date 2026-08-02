<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

final readonly class ManagementPnlRow
{
    public function __construct(
        public int $organizationId,
        public ?int $projectId,
        public ?int $responsibilityCenterId,
        public ?int $budgetArticleId,
        public string $period,
        public string $scenario,
        public string $currency,
        public int $revenueMinor,
        public int $directCostMinor,
        public int $grossMarginMinor,
        public int $operatingExpenseMinor,
        public int $operatingResultMinor,
        public ?string $grossMarginPercent,
        public string $policyVersion,
        public array $sourceRefs,
    ) {
    }
}
