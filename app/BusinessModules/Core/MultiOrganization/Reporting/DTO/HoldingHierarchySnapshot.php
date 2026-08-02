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
    ) {
        if ($holdingId < 1
            || preg_match('/^[a-f0-9]{64}$/D', $version) !== 1
            || $organizationIds === []
            || ! array_is_list($organizationIds)
            || ! in_array($holdingId, $organizationIds, true)) {
            throw new InvalidArgumentException('holding_hierarchy_snapshot_invalid');
        }
        foreach ($organizationIds as $organizationId) {
            if (! is_int($organizationId) || $organizationId < 1) {
                throw new InvalidArgumentException('holding_hierarchy_snapshot_invalid');
            }
        }
    }
}
