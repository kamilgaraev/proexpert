<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;
use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Services\WorkforceCapacityEvidenceBulkPersistence;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityEvidenceItemTest extends TestCase
{
    #[Test]
    public function rejects_canonical_payload_that_does_not_match_the_pinned_hash(): void
    {
        $sourceCanonical = '{"source":{"id":10},"type":"assignment"}';
        $contentCanonical = '{"evidence":{"rate":"1.0000"}}';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_evidence_canonical_hash_mismatch');

        new WorkforceCapacityEvidenceItem(
            sourceType: 'assignment',
            sourceId: 10,
            sourceRevisionHash: hash('sha256', $sourceCanonical),
            sourceCanonical: $sourceCanonical.' ',
            contentHash: hash('sha256', $contentCanonical),
            lineage: [],
            evidence: ['rate' => '1.0000'],
            contentCanonical: $contentCanonical,
        );
    }

    #[Test]
    public function rejects_self_hashed_canonical_payload_that_disagrees_with_dto_fields(): void
    {
        $sourceCanonical = '{"source":{"id":10},"type":"assignment"}';
        $sourceHash = hash('sha256', $sourceCanonical);
        $contentCanonical = json_encode([
            'evidence' => ['rate' => '0.5000'],
            'lineage' => [],
            'revision' => $sourceHash,
            'sealed_employee_id' => null,
            'source_id' => 10,
            'type' => 'assignment',
        ], JSON_THROW_ON_ERROR);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_evidence_canonical_semantics_mismatch');

        new WorkforceCapacityEvidenceItem(
            sourceType: 'assignment',
            sourceId: 10,
            sourceRevisionHash: $sourceHash,
            sourceCanonical: $sourceCanonical,
            contentHash: hash('sha256', $contentCanonical),
            lineage: [],
            evidence: ['rate' => '1.0000'],
            contentCanonical: $contentCanonical,
        );
    }

    #[Test]
    public function bulk_persistence_encodes_jsonb_fields_without_losing_canonical_values(): void
    {
        $sourceCanonical = '{"source":{"id":10},"type":"assignment"}';
        $sourceHash = hash('sha256', $sourceCanonical);
        $lineage = ['organization_id' => 7, 'staff_unit_id' => 11];
        $evidence = ['id' => 10, 'rate' => '1.0000'];
        $contentCanonical = json_encode([
            'evidence' => $evidence,
            'lineage' => $lineage,
            'revision' => $sourceHash,
            'sealed_employee_id' => 41,
            'source_id' => 10,
            'type' => 'assignment',
        ], JSON_THROW_ON_ERROR);
        $item = new WorkforceCapacityEvidenceItem(
            sourceType: 'assignment',
            sourceId: 10,
            sourceRevisionHash: $sourceHash,
            sourceCanonical: $sourceCanonical,
            contentHash: hash('sha256', $contentCanonical),
            lineage: $lineage,
            evidence: $evidence,
            contentCanonical: $contentCanonical,
            sealedEmployeeId: 41,
        );

        $row = (new WorkforceCapacityEvidenceBulkPersistence)->row($item, 1);

        self::assertIsString($row['lineage']);
        self::assertIsString($row['evidence']);
        self::assertSame($lineage, json_decode($row['lineage'], true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($evidence, json_decode($row['evidence'], true, flags: JSON_THROW_ON_ERROR));
    }
}
