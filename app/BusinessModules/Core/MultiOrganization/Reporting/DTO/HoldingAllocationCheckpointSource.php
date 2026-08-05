<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\DTO;

use InvalidArgumentException;

final readonly class HoldingAllocationCheckpointSource
{
    public function __construct(
        public HoldingAllocationFact $fact,
        public array $evidence,
        public string $sourceHash,
    ) {
        if ($evidence === []
            || array_is_list($evidence)
            || ! is_string($evidence['source_type'] ?? null)
            || ! is_int($evidence['source_id'] ?? null)
            || ! is_int($evidence['source_version'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceHash) !== 1) {
            throw new InvalidArgumentException('holding_allocation_checkpoint_source_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return [
            'fact' => get_object_vars($this->fact),
            'evidence' => $this->evidence,
            'source_hash' => $this->sourceHash,
        ];
    }
}
