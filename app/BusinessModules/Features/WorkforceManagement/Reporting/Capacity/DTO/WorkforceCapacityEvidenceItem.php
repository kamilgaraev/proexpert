<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO;

use InvalidArgumentException;

final readonly class WorkforceCapacityEvidenceItem
{
    private const TYPES = [
        'staff_unit',
        'assignment',
        'employee_lifecycle',
        'schedule',
        'schedule_day',
        'absence',
        'business_trip',
        'capacity_gap',
    ];

    public function __construct(
        public string $sourceType,
        public ?int $sourceId,
        public string $sourceRevisionHash,
        public string $sourceCanonical,
        public string $contentHash,
        public array $lineage,
        public array $evidence,
        public string $contentCanonical,
        public ?int $sealedEmployeeId = null,
    ) {
        if (! in_array($this->sourceType, self::TYPES, true)
            || ($this->sourceId !== null && $this->sourceId < 1)
            || ($this->sealedEmployeeId !== null && $this->sealedEmployeeId < 1)) {
            throw new InvalidArgumentException('workforce_capacity_evidence_identity_invalid');
        }

        foreach ([$this->sourceRevisionHash, $this->contentHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new InvalidArgumentException('workforce_capacity_evidence_hash_invalid');
            }
        }

        if (! hash_equals($this->sourceRevisionHash, hash('sha256', $this->sourceCanonical))
            || ! hash_equals($this->contentHash, hash('sha256', $this->contentCanonical))) {
            throw new InvalidArgumentException('workforce_capacity_evidence_canonical_hash_mismatch');
        }
    }

    public function toPersistence(int $position): array
    {
        return [
            'position' => $position,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'source_revision_hash' => $this->sourceRevisionHash,
            'source_canonical' => $this->sourceCanonical,
            'content_hash' => $this->contentHash,
            'lineage' => $this->lineage,
            'evidence' => $this->evidence,
            'content_canonical' => $this->contentCanonical,
            'sealed_employee_id' => $this->sealedEmployeeId,
        ];
    }
}
