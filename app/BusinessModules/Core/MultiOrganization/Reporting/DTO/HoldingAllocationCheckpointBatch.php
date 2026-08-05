<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class HoldingAllocationCheckpointBatch
{
    public function __construct(
        public HoldingHierarchySnapshot $hierarchy,
        public string $coverageStartedAt,
        public array $sources,
        public array $gaps,
        public string $watermark,
    ) {
        if (trim($coverageStartedAt) === ''
            || ! array_is_list($sources)
            || ! array_is_list($gaps)
            || trim($watermark) === '') {
            throw new InvalidArgumentException('holding_allocation_checkpoint_batch_invalid');
        }
        foreach ($sources as $source) {
            if (! $source instanceof HoldingAllocationCheckpointSource) {
                throw new InvalidArgumentException('holding_allocation_checkpoint_batch_invalid');
            }
        }
    }
}
