<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use InvalidArgumentException;

final readonly class ProjectPortfolioHealthSourceComponent
{
    public function __construct(
        public string $kind,
        public string $snapshotId,
        public string $sourceHash,
        public string $version,
        public string $asOf,
    ) {
        if (! in_array($kind, ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS, true)
            || trim($snapshotId) === ''
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1
            || trim($version) === ''
            || strtotime($asOf) === false) {
            throw new InvalidArgumentException('project_portfolio_health_source_component_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'kind' => $this->kind,
            'snapshot_id' => $this->snapshotId,
            'source_hash' => $this->sourceHash,
            'version' => $this->version,
            'as_of' => $this->asOf,
        ];
    }
}
