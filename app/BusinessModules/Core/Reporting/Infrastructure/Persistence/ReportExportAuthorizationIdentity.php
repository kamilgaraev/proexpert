<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportExportRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ReportExportAuthorizationIdentity
{
    public static function fromRecord(ReportExportRecord $record): Sha256Hash
    {
        $artifact = $record->artifact_checksum === null ? null : [
            'storage_key' => $record->artifact_path,
            'etag' => $record->artifact_etag,
            'mime_type' => $record->artifact_mime,
            'sha256' => $record->artifact_checksum,
            'size_bytes' => $record->artifact_size_bytes,
            'row_count' => $record->row_count,
        ];
        $projection = [
            'scope' => [
                'organization_id' => $record->organization_id,
                'holding_organization_ids' => $record->scope_holding_organization_ids,
                'project_ids' => $record->scope_project_ids,
                'resources' => $record->scope_resources,
                'timezone' => $record->scope_timezone,
            ],
            'aggregate' => [
                'export_id' => $record->id,
                'run_id' => $record->run_id,
                'report_code' => $record->report_code,
            ],
            'render_input' => [
                'format' => $record->format,
                'selected_columns' => $record->selected_columns,
                'sort' => [
                    'field' => $record->sort_field,
                    'direction' => $record->sort_direction,
                ],
                'locale' => $record->locale,
                'timezone' => $record->render_timezone,
            ],
            'hashes' => [
                'definition' => $record->definition_hash,
                'query' => $record->query_hash,
                'source' => $record->source_hash,
                'result' => $record->result_hash,
                'export' => $record->export_hash,
                'input_fingerprint' => $record->input_fingerprint,
            ],
            'snapshot' => [
                'kind' => $record->snapshot_kind,
                'id' => $record->snapshot_id,
                'materialized_source_hash' => $record->snapshot_materialized_source_hash,
                'generated_at' => self::instant($record->snapshot_generated_at),
                'stale_at' => self::nullableInstant($record->snapshot_stale_at),
                'watermarks' => $record->snapshot_watermarks,
                'classification' => $record->snapshot_classification,
                'seal' => $record->snapshot_seal_key_id === null ? null : [
                    'key_id' => $record->snapshot_seal_key_id,
                    'algorithm' => $record->snapshot_seal_algorithm,
                    'sealed_payload_hash' => $record->snapshot_sealed_payload_hash,
                    'signature' => $record->snapshot_seal_signature,
                    'sealed_at' => self::instant($record->snapshot_sealed_at),
                ],
            ],
            'classification' => [
                'data' => $record->data_classification,
                'sensitive_column_ids' => $record->sensitive_column_ids,
                'audit_column_ids' => $record->audit_column_ids,
                'totals_sensitive' => $record->totals_sensitive,
                'totals_audit' => $record->totals_audit,
                'provenance_audit' => $record->provenance_audit,
            ],
            'versions' => [
                'contract' => $record->contract_version,
                'formula' => $record->formula_version,
                'source_schema' => $record->source_schema_version,
                'renderer' => $record->renderer_version,
            ],
            'artifact' => $artifact,
        ];

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
    }

    private static function nullableInstant(mixed $value): ?string
    {
        return $value === null ? null : self::instant($value);
    }

    private static function instant(mixed $value): string
    {
        $instant = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);

        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    }
}
