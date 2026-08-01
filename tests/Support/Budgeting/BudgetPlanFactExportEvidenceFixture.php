<?php

declare(strict_types=1);

namespace Tests\Support\Budgeting;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportRunExportSource;
use App\BusinessModules\Core\Reporting\Application\Input\CreateReportExportData;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportCursorRow;
use App\BusinessModules\Core\Reporting\Application\Rows\ReportRowChunk;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotSeal;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\Reporting\ReportDefinitionBuilder;
use Tests\Support\Reporting\ReportRunBuilder;

final class BudgetPlanFactExportEvidenceFixture
{
    /** @return array{0: ReportRunExportSource, 1: \App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition, 2: list<ReportRowChunk>} */
    public static function sealedSource(string $rendererVersion = 'budget-plan-fact-renderer-v1'): array
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
                new Sha256Hash(hash('sha256', 'budget-plan-fact-export-evidence-seal')),
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
        $run = (new ReportRunBuilder)
            ->reportCode(BudgetPlanFactCandidateContract::CODE)
            ->definitionHash($definitionHash)
            ->contractVersion($definition->definition->contractVersion)
            ->formulaVersion($contract->formulaVersion)
            ->sourceSchemaVersion($contract->sourceSchemaVersion)
            ->rendererVersion($definition->definition->rendererVersion)
            ->queryHash($query->queryHash)
            ->sourceHash($sourceHash)
            ->rowCount(count($rows))
            ->resultMetadata($result->metadata)
            ->totals($result->totals)
            ->freshness($result->freshness)
            ->quality($result->quality)
            ->provenance($result->provenance)
            ->updatedAt($generatedAt)
            ->readyAt($generatedAt)
            ->expiresAt(new DateTimeImmutable('2030-01-31T10:00:00+00:00'))
            ->ready();
        $source = new ReportRunExportSource(
            $run,
            $query,
            $result,
            $resultHash,
            $snapshot,
            ReportDataClassification::STANDARD,
            new ReportOutputClassification(ReportDataClassification::STANDARD, [], [], false, false, false),
            $definition->definition->contractVersion,
            $contract->formulaVersion,
            $contract->sourceSchemaVersion,
            $definition->definition->rendererVersion,
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
