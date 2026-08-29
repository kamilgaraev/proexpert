<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO;

use InvalidArgumentException;

final readonly class PayrollReadinessEvidenceItem
{
    public function __construct(
        public string $sourceType,
        public ?int $sourceId,
        public string $code,
        public string $status,
        public string $contentHash,
        public array $lineage,
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/', $this->contentHash)) {
            throw new InvalidArgumentException('payroll_readiness_item_hash_invalid');
        }

        $forbidden = [
            'employee_id',
            'employee_name',
            'hours',
            'message',
            'personnel_number',
            'salary_amount',
            'amount',
        ];

        if (array_intersect(array_keys($this->lineage), $forbidden) !== []) {
            throw new InvalidArgumentException('payroll_readiness_item_contains_restricted_data');
        }
    }

    public function toPersistence(int $position): array
    {
        return [
            'position' => $position,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'evidence_code' => $this->code,
            'evidence_status' => $this->status,
            'content_hash' => $this->contentHash,
            'lineage' => json_encode(
                (object) $this->lineage,
                JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ),
        ];
    }

    public function canonical(): array
    {
        return [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'code' => $this->code,
            'status' => $this->status,
            'content_hash' => $this->contentHash,
            'lineage' => $this->lineage,
        ];
    }
}
