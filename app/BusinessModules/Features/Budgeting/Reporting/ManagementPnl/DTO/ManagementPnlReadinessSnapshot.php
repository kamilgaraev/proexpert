<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

final readonly class ManagementPnlReadinessSnapshot
{
    /**
     * @param  list<string>  $currencies
     * @param  list<string>  $scenarios
     * @param  list<int>  $projectIds
     * @param  list<int>  $responsibilityCenterIds
     * @param  list<int>  $budgetArticleIds
     */
    public function __construct(
        public int $factCount,
        public bool $hasActivePolicy,
        public bool $hasExactSealedTuple,
        public array $currencies,
        public array $scenarios,
        public array $projectIds = [],
        public array $responsibilityCenterIds = [],
        public array $budgetArticleIds = [],
    ) {}
}
