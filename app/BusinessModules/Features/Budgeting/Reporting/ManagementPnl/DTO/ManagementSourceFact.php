<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

use DomainException;

final readonly class ManagementSourceFact
{
    public function __construct(
        public string $sourceSnapshotId,
        public string $sourceType,
        public string $sourceRowKey,
        public string $metricCode,
        public int $organizationId,
        public ?int $projectId,
        public ?int $responsibilityCenterId,
        public ?int $budgetArticleId,
        public string $period,
        public string $scenario,
        public string $currency,
        public int $amountMinor,
        public array $sourceRefs,
    ) {
        if ($organizationId < 1 || trim($sourceSnapshotId) === '' || trim($sourceType) === ''
            || trim($sourceRowKey) === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('management_pnl_source_fact_invalid');
        }
    }

    public function identity(): string
    {
        return $this->sourceSnapshotId."\0".$this->sourceType."\0".$this->sourceRowKey."\0".$this->metricCode;
    }
}
