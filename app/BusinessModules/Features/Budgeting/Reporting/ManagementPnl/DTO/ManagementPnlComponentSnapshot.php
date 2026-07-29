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
    ) {
        if (trim($componentCode) === '' || trim($snapshotId) === '' || trim($formulaVersion) === ''
            || trim($sourceSchemaVersion) === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('management_pnl_component_snapshot_invalid');
        }
        foreach ($facts as $fact) {
            if (!$fact instanceof ManagementSourceFact) {
                throw new DomainException('management_pnl_component_facts_invalid');
            }
        }
    }
}
