<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Persistence;

use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportExportAuthorizationIdentity;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunAuthorizationIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportAuthorizationIdentityTest extends TestCase
{
    #[DataProvider('exportImmutableMutationProvider')]
    public function test_export_identity_changes_for_each_persisted_immutable_hash(
        string $attribute,
        mixed $mutatedValue,
    ): void {
        $record = $this->exportRecord();
        $before = ReportExportAuthorizationIdentity::fromRecord($record);

        $record->setRawAttributes(array_merge(
            $record->getAttributes(),
            [$attribute => $mutatedValue],
        ));

        self::assertNotSame(
            $before->value,
            ReportExportAuthorizationIdentity::fromRecord($record)->value,
            "Export identity ignored immutable {$attribute}.",
        );
    }

    public static function exportImmutableMutationProvider(): iterable
    {
        yield 'export hash' => ['export_hash', str_repeat('1', 64)];
        yield 'input fingerprint' => ['input_fingerprint', str_repeat('2', 64)];
    }

    #[DataProvider('runImmutableMutationProvider')]
    public function test_run_identity_changes_for_each_persisted_immutable_input(
        string $attribute,
        mixed $mutatedValue,
    ): void {
        $record = $this->runRecord();
        $before = ReportRunAuthorizationIdentity::fromRecord($record);

        $record->setRawAttributes(array_merge(
            $record->getAttributes(),
            [$attribute => $mutatedValue],
        ));

        self::assertNotSame(
            $before->value,
            ReportRunAuthorizationIdentity::fromRecord($record)->value,
            "Run identity ignored immutable {$attribute}.",
        );
    }

    public static function runImmutableMutationProvider(): iterable
    {
        yield 'definition snapshot hash' => ['definition_snapshot_hash', str_repeat('3', 64)];
        yield 'input fingerprint' => ['input_fingerprint', str_repeat('4', 64)];
        yield 'saved view id' => ['saved_view_id', '01J00000000000000000000011'];
        yield 'saved view revision' => ['saved_view_revision', 8];
        yield 'saved view hash' => ['saved_view_hash', str_repeat('5', 64)];
    }

    private function exportRecord(): ReportExportRecord
    {
        $record = new ReportExportRecord;
        $record->setDateFormat('Y-m-d H:i:s.uP');
        $record->setRawAttributes([
            'id' => '01J00000000000000000000001',
            'run_id' => '01J00000000000000000000000',
            'organization_id' => 1,
            'report_code' => 'report',
            'export_hash' => str_repeat('a', 64),
            'input_fingerprint' => str_repeat('b', 64),
            'definition_hash' => str_repeat('c', 64),
            'query_hash' => str_repeat('d', 64),
            'source_hash' => str_repeat('e', 64),
            'result_hash' => str_repeat('f', 64),
            'scope_holding_organization_ids' => '[1]',
            'scope_project_ids' => '[10]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'materialized',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => '2026-07-29 09:59:00.000000+00:00',
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'snapshot_seal_key_id' => null,
            'snapshot_seal_algorithm' => null,
            'snapshot_sealed_payload_hash' => null,
            'snapshot_seal_signature' => null,
            'snapshot_sealed_at' => null,
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'totals_sensitive' => false,
            'totals_audit' => false,
            'provenance_audit' => false,
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
            'format' => 'csv',
            'selected_columns' => '["name"]',
            'sort_field' => 'name',
            'sort_direction' => 'asc',
            'locale' => 'ru-RU',
            'render_timezone' => 'UTC',
            'artifact_checksum' => null,
        ]);

        return $record;
    }

    private function runRecord(): ReportRunRecord
    {
        $record = new ReportRunRecord;
        $record->setDateFormat('Y-m-d H:i:s.uP');
        $record->setRawAttributes([
            'id' => '01J00000000000000000000000',
            'organization_id' => 1,
            'report_code' => 'report',
            'definition_hash' => str_repeat('a', 64),
            'definition_snapshot_hash' => str_repeat('b', 64),
            'query_hash' => str_repeat('c', 64),
            'source_hash' => str_repeat('d', 64),
            'result_hash' => str_repeat('e', 64),
            'input_fingerprint' => str_repeat('f', 64),
            'definition_snapshot' => '{"output_classification":{"totals_sensitive":false,"totals_audit":false,"provenance_audit":false}}',
            'saved_view_id' => '01J00000000000000000000010',
            'saved_view_revision' => 7,
            'saved_view_hash' => str_repeat('9', 64),
            'scope_holding_organization_ids' => '[1]',
            'scope_project_ids' => '[10]',
            'scope_resources' => '[]',
            'scope_timezone' => 'UTC',
            'snapshot_kind' => 'materialized',
            'snapshot_id' => 'snapshot-1',
            'snapshot_generated_at' => '2026-07-29 09:59:00.000000+00:00',
            'snapshot_stale_at' => null,
            'snapshot_watermarks' => '[]',
            'snapshot_classification' => 'operational',
            'snapshot_seal_key_id' => null,
            'snapshot_seal_algorithm' => null,
            'snapshot_sealed_payload_hash' => null,
            'snapshot_seal_signature' => null,
            'snapshot_sealed_at' => null,
            'data_classification' => 'standard',
            'sensitive_column_ids' => '[]',
            'audit_column_ids' => '[]',
            'contract_version' => '1',
            'formula_version' => '1',
            'source_schema_version' => '1',
            'renderer_version' => '1',
        ]);

        return $record;
    }
}
