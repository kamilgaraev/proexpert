<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Core\Reporting\Support\OwnerSnapshotIdentityGuard;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportExecutionContextBuilder;

final class OwnerSnapshotIdentityGuardTest extends TestCase
{
    public function test_canonical_hash_does_not_replace_the_persisted_materialized_identity(): void
    {
        [$context, $snapshot, $record] = $this->fixture();
        self::assertNotSame($snapshot->sourceHash->value, $snapshot->materializedSourceHash->value);
        (new OwnerSnapshotIdentityGuard)->assert($record, $context, $snapshot, ['source_watermark_id' => 'source_fact_id']);
        self::assertSame($record->source_hash, $snapshot->materializedSourceHash->value);
    }

    public static function changedIdentity(): array
    {
        return [
            'organization' => ['organization_id', 2], 'scope' => ['scope_hash', str_repeat('f', 64)],
            'query' => ['query_hash', str_repeat('f', 64)], 'definition' => ['definition_hash', str_repeat('f', 64)],
            'formula' => ['formula_version', 'changed'], 'materialized hash' => ['source_hash', str_repeat('f', 64)],
            'canonical hash in source column' => ['source_hash', str_repeat('c', 64)],
            'as of' => ['as_of', '2026-10-23T12:00:00+00:00'], 'watermark' => ['source_watermark_id', 8],
        ];
    }

    #[DataProvider('changedIdentity')]
    public function test_a_changed_persisted_identity_is_still_rejected(string $column, string|int $value): void
    {
        [$context, $snapshot, $record] = $this->fixture();
        $record->{$column} = $value;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('report_snapshot_identity_mismatch');
        (new OwnerSnapshotIdentityGuard)->assert($record, $context, $snapshot, ['source_watermark_id' => 'source_fact_id']);
    }

    private function fixture(): array
    {
        $context = (new ReportExecutionContextBuilder)->build();
        $snapshot = new ReportSnapshotRef(
            'contract_settlement_exposure', 'snapshot-test', $context->scope, new Sha256Hash(str_repeat('d', 64)),
            'formula-v1', new Sha256Hash(str_repeat('c', 64)), new DateTimeImmutable('2026-10-22T12:00:00Z'),
            null, ['query_hash' => str_repeat('b', 64), 'as_of' => '2026-10-22T12:00:00+00:00', 'source_fact_id' => 7],
            ReportSnapshotClassification::OPERATIONAL, null, new Sha256Hash(str_repeat('a', 64)),
        );
        $record = (object) [
            'organization_id' => 1, 'scope_hash' => hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity())),
            'query_hash' => str_repeat('b', 64), 'definition_hash' => str_repeat('d', 64), 'formula_version' => 'formula-v1',
            'source_hash' => str_repeat('a', 64), 'as_of' => '2026-10-22T12:00:00+00:00', 'source_watermark_id' => 7,
        ];

        return [$context, $snapshot, $record];
    }
}
