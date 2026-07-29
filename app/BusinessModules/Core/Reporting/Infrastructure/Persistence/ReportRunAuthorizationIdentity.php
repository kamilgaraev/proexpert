<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Persistence;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final class ReportRunAuthorizationIdentity
{
    public static function fromRecord(ReportRunRecord $record): Sha256Hash
    {
        $definitionSnapshot = $record->definition_snapshot;
        $classification = is_array($definitionSnapshot)
            ? ($definitionSnapshot['output_classification'] ?? null)
            : null;
        if (! is_array($classification)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
        }

        $projection = [
            'scope' => [
                'organization_id' => $record->organization_id,
                'holding_organization_ids' => $record->scope_holding_organization_ids,
                'project_ids' => $record->scope_project_ids,
                'resources' => self::resources($record),
                'timezone' => $record->scope_timezone,
            ],
            'aggregate' => [
                'run_id' => $record->id,
                'report_code' => $record->report_code,
            ],
            'hashes' => [
                'definition' => $record->definition_hash,
                'definition_snapshot' => $record->definition_snapshot_hash,
                'query' => $record->query_hash,
                'source' => $record->source_hash,
                'result' => $record->result_hash,
                'input_fingerprint' => $record->input_fingerprint,
            ],
            'saved_view' => self::savedView($record),
            'snapshot' => [
                'kind' => $record->snapshot_kind,
                'id' => $record->snapshot_id,
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
                'totals_sensitive' => $classification['totals_sensitive'] ?? null,
                'totals_audit' => $classification['totals_audit'] ?? null,
                'provenance_audit' => $classification['provenance_audit'] ?? null,
            ],
            'versions' => [
                'contract' => $record->contract_version,
                'formula' => $record->formula_version,
                'source_schema' => $record->source_schema_version,
                'renderer' => $record->renderer_version,
            ],
        ];

        return new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
    }

    private static function savedView(ReportRunRecord $record): ?array
    {
        if ($record->saved_view_id === null
            && $record->saved_view_revision === null
            && $record->saved_view_hash === null) {
            return null;
        }

        return [
            'id' => $record->saved_view_id,
            'revision' => $record->saved_view_revision,
            'hash' => $record->saved_view_hash,
        ];
    }

    private static function resources(ReportRunRecord $record): array
    {
        $resources = $record->getAttribute('scope_resources');
        if (is_array($resources)) {
            return $resources;
        }
        $raw = $record->getRawOriginal('scope_resource_ids');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }

        throw ReportContractException::fromCode(ReportErrorCode::REPORT_INTERNAL_ERROR);
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
