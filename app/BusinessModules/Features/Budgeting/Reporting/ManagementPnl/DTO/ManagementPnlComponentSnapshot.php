<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use DomainException;

final readonly class ManagementPnlComponentSnapshot
{
    public function __construct(
        public string $componentCode,
        public string $snapshotId,
        public Sha256Hash $sourceHash,
        public string $formulaVersion,
        public string $sourceSchemaVersion,
        public string $periodFrom,
        public string $periodTo,
        public string $scenario,
        public string $currency,
        public array $facts,
        public ?string $scopeHash = null,
        public ?string $queryHash = null,
        public ?string $definitionHash = null,
        public ?string $asOf = null,
        public ?int $rowCount = null,
        public ?int $coverageNumerator = null,
        public ?int $coverageDenominator = null,
        public array $warnings = [],
    ) {
        if (trim($componentCode) === '' || trim($snapshotId) === '' || trim($formulaVersion) === ''
            || trim($sourceSchemaVersion) === '' || ! preg_match('/^[A-Z]{3}$/', $currency)
            || ($scopeHash !== null && preg_match('/^[a-f0-9]{64}$/D', $scopeHash) !== 1)
            || ($queryHash !== null && preg_match('/^[a-f0-9]{64}$/D', $queryHash) !== 1)
            || ($definitionHash !== null && preg_match('/^[a-f0-9]{64}$/D', $definitionHash) !== 1)
            || ($rowCount !== null && $rowCount < 0)
            || ($coverageNumerator !== null && $coverageNumerator < 0)
            || ($coverageDenominator !== null && $coverageDenominator < 0)
            || ($coverageNumerator !== null && $coverageDenominator !== null
                && $coverageNumerator > $coverageDenominator)) {
            throw new DomainException('management_pnl_component_snapshot_invalid');
        }
        foreach ($facts as $fact) {
            if (! $fact instanceof ManagementSourceFact) {
                throw new DomainException('management_pnl_component_facts_invalid');
            }
        }
    }
}
