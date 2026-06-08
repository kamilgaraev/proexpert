<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

final readonly class BudgetingCatalogs
{
    /**
     * @param array<int, array<string, mixed>> $periods
     * @param array<int, array<string, mixed>> $scenarios
     * @param array<int, array<string, mixed>> $articles
     * @param array<int, array<string, mixed>> $responsibilityCenters
     */
    public function __construct(
        public array $periods,
        public array $scenarios,
        public array $articles,
        public array $responsibilityCenters,
    ) {
    }

    public function toArray(): array
    {
        return [
            'periods' => $this->periods,
            'scenarios' => $this->scenarios,
            'articles' => $this->articles,
            'responsibility_centers' => $this->responsibilityCenters,
        ];
    }
}
