<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Support;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\Support\Reporting\OwnerSnapshotResultFactory;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OwnerSnapshotResultFactoryTest extends TestCase
{
    #[Test]
    public function semantic_schema_version_is_encoded_as_safe_source_identifier(): void
    {
        $factory = new OwnerSnapshotResultFactory;
        $snapshot = $factory->snapshot(
            'supplier_award_competitiveness',
            '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            new ReportScope(1, [1], [52], [], new DateTimeZone('UTC')),
            new Sha256Hash(str_repeat('a', 64)),
            'supplier-award.v1',
            str_repeat('b', 64),
            new DateTimeImmutable('2026-08-09T00:00:00+00:00'),
            null,
            ['as_of' => '2026-08-09T00:00:00+00:00'],
        );

        $result = $factory->result(
            $snapshot,
            0,
            0,
            [],
            'supplier_award_competitiveness',
            'supplier-award.v1',
            '2026-08-09T00:00:00+00:00',
            [['id' => 'row_key']],
            ['export' => true],
            ReportReconciliationStatus::NOT_APPLICABLE,
        );

        self::assertSame(
            'schema_'.substr(hash('sha256', 'supplier-award.v1'), 0, 32),
            $result->provenance->sourceRefs[0]->schemaVersion,
        );
    }

    #[Test]
    public function source_reference_keeps_materialized_hash_when_canonical_hash_differs(): void
    {
        $factory = new OwnerSnapshotResultFactory;
        $canonicalHash = new Sha256Hash(str_repeat('c', 64));
        $materializedHash = new Sha256Hash(str_repeat('d', 64));
        $generatedAt = new DateTimeImmutable('2026-08-09T00:00:00+00:00');
        $snapshot = new ReportSnapshotRef(
            kind: 'supplier_award_competitiveness',
            id: '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            scope: new ReportScope(1, [1], [52], [], new DateTimeZone('UTC')),
            definitionHash: new Sha256Hash(str_repeat('a', 64)),
            formulaVersion: 'supplier-award.v1',
            sourceHash: $canonicalHash,
            generatedAt: $generatedAt,
            staleAt: null,
            watermarks: ['as_of' => '2026-08-09T00:00:00+00:00'],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
            materializedSourceHash: $materializedHash,
        );

        $result = $factory->result(
            $snapshot,
            0,
            0,
            [],
            'supplier_award_competitiveness',
            'supplier-award.v1',
            '2026-08-09T00:00:00+00:00',
            [['id' => 'row_key']],
            ['export' => true],
            ReportReconciliationStatus::NOT_APPLICABLE,
        );

        self::assertSame($canonicalHash->value, $result->provenance->sourceHash->value);
        self::assertSame($materializedHash->value, $result->provenance->sourceRefs[0]->hash->value);
    }
}
