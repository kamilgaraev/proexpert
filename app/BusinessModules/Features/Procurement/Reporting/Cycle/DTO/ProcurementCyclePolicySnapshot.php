<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO;

use InvalidArgumentException;

final readonly class ProcurementCyclePolicySnapshot
{
    public string $canonicalHash;

    public function __construct(
        public int $versionId,
        public ProcurementCyclePolicyDefinition $definition,
    ) {
        if ($this->versionId < 1) {
            throw new InvalidArgumentException('procurement_cycle_policy_snapshot_invalid');
        }

        $this->canonicalHash = $this->definition->canonicalHash();
    }
}
