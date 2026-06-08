<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\DTOs;

use Carbon\CarbonImmutable;

final readonly class BudgetImportValidationContext
{
    /**
     * @param array<string, array{id:int, uuid:string, code:string, name:string, budget_kind:string, is_leaf:bool, is_active:bool}> $articlesByCode
     * @param array<string, array{id:int, uuid:string, code:string, name:string, budget_kind:string, is_leaf:bool, is_active:bool}> $articlesByName
     * @param array<string, array{id:int, uuid:string, code:string, name:string, is_active:bool, active_from:?string, active_to:?string}> $centersByCode
     * @param array<string, array{id:int, uuid:string, code:string, name:string, is_active:bool, active_from:?string, active_to:?string}> $centersByName
     * @param array<int, true> $projectIds
     * @param array<int, true> $contractIds
     * @param array<int, true> $counterpartyIds
     */
    public function __construct(
        public int $organizationId,
        public string $budgetKind,
        public string $versionUuid,
        public string $versionStatus,
        public string $periodStatus,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $scenarioCode,
        public string $currency,
        public string $mappingMode,
        public array $articlesByCode,
        public array $articlesByName,
        public array $centersByCode,
        public array $centersByName,
        public array $projectIds = [],
        public array $contractIds = [],
        public array $counterpartyIds = [],
    ) {
    }
}
