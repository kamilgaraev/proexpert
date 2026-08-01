<?php

declare(strict_types=1);

namespace Tests\Support\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportDefinitionSnapshotDecoder;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\ReportRunHydrator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class BudgetPlanFactExportEvidenceFixture
{
    /** @return array{0: ReportRunExportSource, 1: \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition, 2: list<ReportRowChunk>} */
    public static function sealedSource(string $rendererVersion = 'budget-plan-fact-renderer-v1', bool $tamperDefinitionHash = false): array
    {
        $contract = new BudgetPlanFactCandidateContract;
        $contract->assertRuntimeMatches();
        $write = BudgetPlanFactCandidateFixture::snapshot();
        $definitionHash = new Sha256Hash(hash('sha256', 'budget-plan-fact-export-evidence-definition'));
        $columns = array_map(
            static fn (array $column): array => ['id' => $column['id'], 'labels' => ['ru-RU' => $column['id']]],
            $contract->columns(),
        );
        $definition = (new ReportDefinitionBuilder)
            ->code(BudgetPlanFactCandidateContract::CODE)
            ->definitionHash($definitionHash)
            ->contractVersion('budget-plan-fact-contract-v1')
            ->formulaVersion($contract->formulaVersion)
            ->sourceSchemaVersion($contract->sourceSchemaVersion)
            ->rendererVersion($rendererVersion)
            ->filters($contract->filters())
            ->columns($columns)
            ->sorts($contract->sorts())
            ->formats($contract->formats())
            ->snapshotClassification(ReportSnapshotClassification::OFFICIAL)
            ->published();
        $filters = ['close_id' => BudgetPlanFactCandidateFixture::closeId(), ...BudgetPlanFactCandidateFixture::filters()];
        $scope = BudgetPlanFactCandidateFixture::scope();
        $query = new ReportQuery(
            $definition->definition,
            $scope,
            new ReportFilterSet($filters),
            [],
            new DateTimeImmutable('2026-01-31T10:00:00+00:00'),
            'ru-RU',
        );
        $sourceHash = $write->header->snapshotHash;
        $generatedAt = $write->header->generatedAt;
        $snapshot = new ReportSnapshotRef(
            $write->header->sourceKind,
            $write->header->id,
            $scope,
            $definitionHash,
            $contract->formulaVersion,
            $sourceHash,
            $generatedAt,
            null,
            ['report_query_hash' => $query->queryHash->value],
            ReportSnapshotClassification::OFFICIAL,
            new ReportSnapshotSeal(
                'budget-plan-fact-evidence-key',
                'ed25519-sha256',
                $sourceHash,
                rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '='),
                $generatedAt,
            ),
            $write->header->sourceHash,
        );
        $rows = array_map(
            static fn ($row): array => ['row_key' => $row->rowKey, ...$row->payload],
            $write->rows,
        );
        $result = new ReportResult(
            new ReportResultMetadata($snapshot, count($rows), $generatedAt, null),
            [],
            ReportFreshnessStatus::FRESH,
            new ReportQuality(ReportQualityStatus::COMPLETE, null, [], 0, ReportReconciliationStatus::MATCHED, [], []),
            new ReportProvenance(
                'budgeting',
                [new ReportSourceRef('budgeting', 'source_snapshot', 'snapshot', $contract->sourceSchemaVersion, 'approved_close', count($rows), $sourceHash)],
                $sourceHash,
                null,
            ),
            $columns,
            ['drill_down' => true, 'sealed_source_snapshot' => true],
        );
        $projection = (new \ReflectionClass(ReportRunExportSource::class))->getMethod('resultProjection')->invoke(null, $result);
        $resultHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
        $record = self::sealedReadyRecord($definition->definition, $query, $result, $snapshot, $resultHash);
        if ($tamperDefinitionHash) {
            $record->definition_snapshot_hash = str_repeat('f', 64);
        }
        $source = (new ReportRunHydrator)->exportSource(
            $record,
            1000,
        );
        $cursorRows = array_map(
            static fn (array $row): ReportCursorRow => new ReportCursorRow(
                $row['row_key'],
                $row,
                $snapshot->id,
                $query->queryHash,
                $snapshot->sourceHash,
            ),
            $rows,
        );

        return [$source, $definition, [new ReportRowChunk($cursorRows)]];
    }

    private static function sealedReadyRecord(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition, ReportQuery $query, ReportResult $result, ReportSnapshotRef $snapshot, Sha256Hash $resultHash): ReportRunRecord
    {
        $snapshotDefinition = [
            'snapshot_schema' => ReportDefinitionSnapshotDecoder::CURRENT_SCHEMA,
            'code' => $definition->code, 'definition_hash' => $definition->definitionHash->value, 'contract_version' => $definition->contractVersion,
            'formula_version' => $definition->formulaVersion, 'source_schema_version' => $definition->sourceSchemaVersion, 'renderer_version' => $definition->rendererVersion,
            'filters' => $definition->filters, 'columns' => $definition->columns, 'sorts' => $definition->sorts, 'formats' => $definition->formats,
            'permission_policy' => ['view_permissions' => $definition->permissionPolicy->viewPermissions, 'export_permissions' => $definition->permissionPolicy->exportPermissions, 'sensitive_permissions' => [], 'audit_permissions' => []],
            'snapshot_classification' => $definition->snapshotClassification->value,
            'output_classification' => ['default_classification' => $definition->outputClassification->defaultClassification->value, 'sensitive_column_ids' => [], 'audit_column_ids' => [], 'totals_sensitive' => false, 'totals_audit' => false, 'provenance_audit' => false],
            'publication_readiness' => $definition->publicationReadiness->value, 'supports_subscriptions' => false, 'source_module' => $definition->sourceModule, 'core_access_mode' => $definition->coreAccessMode->value,
        ];
        $definitionHash = hash('sha256', CanonicalJson::encode($snapshotDefinition));
        $queryPayload = json_decode($query->canonicalJson, true, 512, JSON_THROW_ON_ERROR);
        $quality = ['status' => 'complete', 'coverage' => null, 'warnings' => [], 'unmatched_count' => 0, 'reconciliation' => 'matched', 'unknown_metrics' => [], 'excluded_sources' => []];
        $provenance = ['source_of_truth' => 'budgeting', 'source_refs' => [['source' => 'budgeting', 'snapshot_kind' => 'source_snapshot', 'snapshot_id' => 'snapshot', 'schema_version' => $definition->sourceSchemaVersion, 'watermark' => 'approved_close', 'row_count' => $result->metadata->rowCount, 'hash' => $snapshot->sourceHash->value]], 'source_hash' => $snapshot->sourceHash->value, 'external_confirmation_role' => null];
        $seal = $snapshot->seal;
        if ($seal === null) {
            throw new \LogicException('budget_plan_fact_export_evidence_seal_missing');
        }
        $stamp = $snapshot->generatedAt->format('Y-m-d\\TH:i:s.u\\Z');
        $record = new ReportRunRecord;
        $record->setRawAttributes([
            'id' => '01J00000000000000000000000', 'organization_id' => $query->scope->organizationId, 'requester_actor_id' => 17, 'report_code' => $definition->code, 'status' => 'ready',
            'definition_hash' => $definition->definitionHash->value, 'definition_snapshot_hash' => $definitionHash, 'query_hash' => $query->queryHash->value, 'source_hash' => $snapshot->sourceHash->value,
            'idempotency_key_hash' => hash('sha256', 'budget-plan-fact-export-evidence'), 'input_fingerprint' => hash('sha256', CanonicalJson::encode(['definition_snapshot_hash' => $definitionHash, 'query' => $queryPayload, 'saved_view' => null])),
            'contract_version' => $definition->contractVersion, 'formula_version' => $definition->formulaVersion, 'source_schema_version' => $definition->sourceSchemaVersion, 'renderer_version' => $definition->rendererVersion,
            'definition_snapshot' => CanonicalJson::encode($snapshotDefinition), 'canonical_query_json' => $query->canonicalJson, 'scope_holding_organization_ids' => CanonicalJson::encode($query->scope->holdingOrganizationIds), 'scope_project_ids' => CanonicalJson::encode($query->scope->projectIds), 'scope_resources' => '[]', 'scope_timezone' => $query->scope->timezone->getName(), 'filters' => CanonicalJson::encode($query->filters->values), 'comparison' => '[]', 'as_of' => $query->asOf->format('Y-m-d\\TH:i:s.u\\Z'), 'locale' => $query->locale,
            'saved_view_id' => null, 'saved_view_revision' => null, 'saved_view_hash' => null, 'snapshot_classification' => $snapshot->classification->value, 'data_classification' => 'standard', 'sensitive_column_ids' => '[]', 'audit_column_ids' => '[]', 'progress' => 100,
            'result_hash' => $resultHash->value, 'row_count' => $result->metadata->rowCount, 'result_metadata' => CanonicalJson::encode(['row_count' => $result->metadata->rowCount, 'generated_at' => $stamp, 'stale_at' => null]), 'totals' => '[]', 'freshness' => 'fresh', 'quality' => CanonicalJson::encode($quality), 'provenance' => CanonicalJson::encode($provenance), 'row_schema' => CanonicalJson::encode($result->rowSchema), 'capabilities' => CanonicalJson::encode($result->capabilities),
            'snapshot_kind' => $snapshot->kind, 'snapshot_id' => $snapshot->id, 'snapshot_generated_at' => $stamp, 'snapshot_stale_at' => null, 'snapshot_watermarks' => CanonicalJson::encode($snapshot->watermarks), 'snapshot_seal_key_id' => $seal->keyId, 'snapshot_seal_algorithm' => $seal->algorithm, 'snapshot_sealed_payload_hash' => $seal->sealedPayloadHash->value, 'snapshot_seal_signature' => $seal->signature, 'snapshot_sealed_at' => $seal->sealedAt->format('Y-m-d\\TH:i:s.u\\Z'),
            'execution_lease_token' => null, 'execution_lease_expires_at' => null, 'execution_heartbeat_at' => null, 'ready_at' => $stamp, 'created_at' => $stamp, 'updated_at' => $stamp, 'expires_at' => '2030-01-31T10:00:00.000000Z', 'cancel_requested_at' => null, 'error_code' => null,
        ], true);

        return $record;
    }

    public static function export(string $format): CreateReportExportData
    {
        return new CreateReportExportData(
            $format,
            array_column(BudgetPlanFactCandidateFixture::contract()->columns(), 'id'),
            new ReportWindowSort('row_key', ReportSortDirection::ASC),
            'ru-RU',
            new DateTimeZone('UTC'),
        );
    }
}
