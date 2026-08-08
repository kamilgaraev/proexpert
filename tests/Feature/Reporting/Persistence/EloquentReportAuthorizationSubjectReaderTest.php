<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class EloquentReportAuthorizationSubjectReaderTest extends TestCase
{
    public function test_ready_snapshot_reader_accepts_associative_watermarks(): void
    {
        $record = $this->runRecord([
            'snapshot_kind' => 'materialized',
            'snapshot_id' => 'snapshot-1',
            'definition_hash' => str_repeat('a', 64),
            'formula_version' => 'formula-v1',
            'source_hash' => str_repeat('b', 64),
            'snapshot_generated_at' => '2026-08-08 00:00:00+00',
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => json_encode(['attendance_source' => 'max_id_0'], JSON_THROW_ON_ERROR),
            'snapshot_classification' => 'operational',
            'snapshot_seal_key_id' => null,
            'snapshot_seal_algorithm' => null,
            'snapshot_sealed_payload_hash' => null,
            'snapshot_seal_signature' => null,
            'snapshot_sealed_at' => null,
        ]);
        $reader = new EloquentReportAuthorizationSubjectReader(new \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator);

        $snapshot = $this->method('snapshot')->invoke(
            $reader,
            $record,
            new ReportScope(38, [38], [52], [], new DateTimeZone('UTC')),
        );

        self::assertSame(['attendance_source' => 'max_id_0'], $snapshot->watermarks);
    }

    public function test_reader_is_the_closed_persistence_adapter_for_authorization_subjects(): void
    {
        self::assertTrue(is_subclass_of(EloquentReportAuthorizationSubjectReader::class, ReportAuthorizationSubjectReader::class));
    }

    public function test_export_reader_parent_identity_guard_fails_closed_on_result_drift(): void
    {
        $reader = new EloquentReportAuthorizationSubjectReader(new \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator);
        $method = $this->method('matchesParentIdentity');
        $export = $this->exportRecord($this->identityAttributes());
        $run = $this->runRecord($this->identityAttributes());

        self::assertTrue($method->invoke($reader, $export, $run));

        $run->result_hash = str_repeat('b', 64);
        self::assertFalse($method->invoke($reader, $export, $run));
    }

    public function test_export_reader_parent_identity_guard_fails_closed_for_every_persisted_identity_field(): void
    {
        $reader = new EloquentReportAuthorizationSubjectReader(new \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator);
        $method = $this->method('matchesParentIdentity');
        $attributes = $this->identityAttributes();
        $export = $this->exportRecord($attributes);

        foreach ($attributes as $attribute => $value) {
            $drifted = $attributes;
            $drifted[$attribute] = $this->differentValue($attribute, $value);

            self::assertFalse(
                $method->invoke($reader, $export, $this->runRecord($drifted)),
                "Expected {$attribute} drift to be rejected.",
            );
        }
    }

    public function test_export_reader_parent_identity_guard_covers_hash_snapshot_version_and_classification_fields(): void
    {
        $pairs = $this->method('parentIdentityPairs')->invoke(
            new EloquentReportAuthorizationSubjectReader(new \App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator),
            $this->exportRecord($this->identityAttributes()),
            $this->runRecord($this->identityAttributes()),
        );

        self::assertCount(26, $pairs);
        self::assertContains([str_repeat('b', 64), str_repeat('b', 64)], $pairs);
        self::assertContains([str_repeat('c', 64), str_repeat('c', 64)], $pairs);
        self::assertContains(['snapshot-1', 'snapshot-1'], $pairs);
        self::assertContains(['payload-hash', 'payload-hash'], $pairs);
        self::assertContains(['sensitive', 'sensitive'], $pairs);
        self::assertContains(['renderer-v1', 'renderer-v1'], $pairs);
    }

    private function method(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(EloquentReportAuthorizationSubjectReader::class, $name);
        $method->setAccessible(true);

        return $method;
    }

    private function identityAttributes(): array
    {
        return [
            'report_code' => 'cost_control',
            'query_hash' => str_repeat('c', 64),
            'source_hash' => str_repeat('d', 64),
            'result_hash' => str_repeat('e', 64),
            'snapshot_kind' => 'report-run',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => '2026-07-28 10:00:00.000000+00',
            'snapshot_stale_at' => '2026-07-28 11:00:00.000000+00',
            'snapshot_watermarks' => json_encode([['source' => 'erp', 'watermark' => '1']], JSON_THROW_ON_ERROR),
            'snapshot_classification' => 'official',
            'snapshot_seal_key_id' => 'key-1',
            'snapshot_seal_algorithm' => 'hmac-sha256',
            'snapshot_sealed_payload_hash' => 'payload-hash',
            'snapshot_seal_signature' => 'signature',
            'snapshot_sealed_at' => '2026-07-28 10:01:00.000000+00',
            'data_classification' => 'sensitive',
            'sensitive_column_ids' => ['amount'],
            'audit_column_ids' => ['status'],
            'totals_sensitive' => true,
            'totals_audit' => false,
            'provenance_audit' => true,
            'contract_version' => 'contract-v1',
            'formula_version' => 'formula-v1',
            'source_schema_version' => 'schema-v1',
            'renderer_version' => 'renderer-v1',
        ];
    }

    private function exportRecord(array $attributes): ReportExportRecord
    {
        $record = new ReportExportRecord;
        $record->setRawAttributes($attributes, true);

        return $record;
    }

    private function runRecord(array $attributes): ReportRunRecord
    {
        $record = new ReportRunRecord;
        $record->setRawAttributes($attributes, true);

        return $record;
    }

    private function differentValue(string $attribute, mixed $value): mixed
    {
        if (is_bool($value)) {
            return ! $value;
        }
        if (is_array($value)) {
            return [...$value, '__drift__'];
        }
        if (str_ends_with($attribute, '_at')) {
            return '2026-07-29 10:00:00.000000+00';
        }
        if (str_contains($attribute, 'hash')) {
            return str_repeat('f', 64);
        }

        return "{$value}-drift";
    }
}
