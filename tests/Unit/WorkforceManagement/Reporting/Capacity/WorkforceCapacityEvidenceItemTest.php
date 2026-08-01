<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityEvidenceItem;
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
}
