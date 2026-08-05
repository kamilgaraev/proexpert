<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class HoldingHierarchySnapshot
{
    public function __construct(
        public int $holdingId,
        public string $version,
        public array $organizationIds,
        public ?string $coverageStartedAt = null,
        public array $evidenceHashes = [],
        public ?string $coverageHash = null,
    ) {
        if ($holdingId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1
            || $organizationIds === []
            || ! array_is_list($organizationIds)
            || ! in_array($holdingId, $organizationIds, true)
            || ($coverageStartedAt !== null && trim($coverageStartedAt) === '')
            || ! array_is_list($evidenceHashes)
            || ($coverageHash !== null && preg_match('/^[a-f0-9]{64}$/D', $coverageHash) !== 1)) {
            throw new InvalidArgumentException('holding_hierarchy_snapshot_invalid');
        }
        foreach ($organizationIds as $organizationId) {
            if (! is_int($organizationId) || $organizationId < 1) {
                throw new InvalidArgumentException('holding_hierarchy_snapshot_invalid');
            }
        }
        foreach ($evidenceHashes as $evidenceHash) {
            if (! is_string($evidenceHash)
                || preg_match('/^[a-f0-9]{64}$/D', $evidenceHash) !== 1) {
                throw new InvalidArgumentException('holding_hierarchy_snapshot_invalid');
            }
        }
    }
}
