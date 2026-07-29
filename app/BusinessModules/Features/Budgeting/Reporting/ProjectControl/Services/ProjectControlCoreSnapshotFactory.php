<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceIdentity;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlBaselineVersion;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ProjectControlCoreSnapshotFactory
{
    private const FORMULA_VERSION = 'project_control_core.v1';

    public function __construct(
        private ProjectControlFormula $formula,
        private ProjectControlSourceAssembler $sources,
    ) {
    }

    public function capture(
        ProjectControlSourceIdentity $identity,
        ReportQuery $query,
        iterable $sourceRows,
        int $approvedBy,
        DateTimeImmutable $approvedAt,
    ): ReportSnapshotRef {
        if ($approvedBy < 1
            || $identity->organizationId !== $query->scope->organizationId
            || !in_array($identity->projectId, $query->scope->projectIds, true)
            || $query->asOf->format('Y-m-d') !== $identity->statusDate->format('Y-m-d')
            || $query->definition->snapshotClassification !== ReportSnapshotClassification::OPERATIONAL
        ) {
            throw new InvalidArgumentException('project_control_capture_identity_invalid');
        }

        $rows = [];
        foreach ($sourceRows as $row) {
            if (!$row instanceof ProjectControlSourceRow
                || $row->projectId !== $identity->projectId
            ) {
                throw new InvalidArgumentException('project_control_capture_row_invalid');
            }
            $rows[] = $row;
        }
        usort($rows, static fn (ProjectControlSourceRow $left, ProjectControlSourceRow $right): int => strcmp($left->rowKey, $right->rowKey));
        $rowKeys = array_column(array_map(
            static fn (ProjectControlSourceRow $row): array => ['row_key' => $row->rowKey],
            $rows,
        ), 'row_key');
        if (count(array_unique($rowKeys, SORT_STRING)) !== count($rowKeys)) {
            throw new InvalidArgumentException('project_control_capture_row_duplicate');
        }

        $canonicalRows = array_map(
            static fn (ProjectControlSourceRow $row): array => $row->canonicalIdentity(),
            $rows,
        );
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'identity' => $identity->canonicalIdentity(),
            'rows' => $canonicalRows,
            'source_contract' => self::FORMULA_VERSION,
        ])));

        try {
            return DB::transaction(function () use (
                $identity,
                $query,
                $approvedBy,
                $approvedAt,
                $canonicalRows,
                $rows,
                $sourceHash,
            ): ReportSnapshotRef {
                $baseline = ProjectControlBaselineVersion::query()->firstOrCreate(
                    [
                        'organization_id' => $identity->organizationId,
                        'project_id' => $identity->projectId,
                        'schedule_id' => $identity->scheduleId,
                        'version_number' => $identity->baselineVersion,
                    ],
                    [
                        'approved_at' => $approvedAt,
                        'approved_by' => $approvedBy,
                        'source_hash' => $identity->sourceHash,
                        'source_payload' => [
                            'rows' => $canonicalRows,
                            'status_date' => $identity->statusDate->format('Y-m-d'),
                        ],
                    ],
                );
                if (!hash_equals((string) $baseline->source_hash, $identity->sourceHash)) {
                    throw new InvalidArgumentException('project_control_baseline_immutable');
                }

                $existing = ProjectControlSnapshot::query()
                    ->where('organization_id', $identity->organizationId)
                    ->where('query_hash', $query->queryHash->value)
                    ->where('source_hash', $sourceHash->value)
                    ->first();
                if ($existing !== null) {
                    return $this->reference($query->scope, $query, $existing);
                }

                $snapshotId = (string) Str::ulid();
                $metrics = [];
                foreach ($rows as $row) {
                    $metrics[] = [$row, $this->formula->calculate($row->amounts)];
                }
                $totals = [];
                foreach ($metrics as [$sourceRow, $metric]) {
                    $totals[$metric->currency][] = $metric;
                }
                $totals = array_map(
                    fn (array $currencyRows): array => $this->formula->total($currencyRows)->toArray(),
                    $totals,
                );
                ksort($totals, SORT_STRING);
                $watermarks = [
                    'actual_cost' => $identity->actualCostWatermark,
                    'baseline' => 'version_'.$identity->baselineVersion,
                    'progress' => $identity->progressWatermark,
                    'wip' => $identity->wipVersion,
                ];
                $sourceRefs = [[
                    'source' => 'budgeting',
                    'snapshot_kind' => 'project_control',
                    'snapshot_id' => 'snapshot_'.strtolower($snapshotId),
                    'schema_version' => 'project_control_v1',
                    'watermark' => 'source_'.substr($sourceHash->value, 0, 24),
                    'row_count' => count($rows),
                    'hash' => $sourceHash->value,
                ]];
                $snapshot = ProjectControlSnapshot::query()->create([
                    'id' => $snapshotId,
                    'organization_id' => $identity->organizationId,
                    'project_id' => $identity->projectId,
                    'baseline_version_id' => (int) $baseline->id,
                    'status_date' => $identity->statusDate,
                    'wip_version' => $identity->wipVersion,
                    'progress_watermark' => $identity->progressWatermark,
                    'actual_cost_watermark' => $identity->actualCostWatermark,
                    'formula_version' => self::FORMULA_VERSION,
                    'definition_hash' => $query->definition->definitionHash->value,
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'generated_at' => $query->asOf,
                    'stale_at' => $query->asOf->modify('+1 day'),
                    'watermarks' => $watermarks,
                    'totals' => ['currencies' => $totals],
                    'source_refs' => $sourceRefs,
                    'row_schema' => $this->rowSchema(),
                    'row_count' => count($rows),
                ]);

                foreach ($metrics as [$sourceRow, $metric]) {
                    ProjectControlRow::query()->create([
                        'organization_id' => $identity->organizationId,
                        'snapshot_id' => $snapshotId,
                        'row_key' => $sourceRow->rowKey,
                        'project_id' => $sourceRow->projectId,
                        'task_id' => $sourceRow->taskId,
                        'wbs_code' => $sourceRow->wbsCode,
                        'contractor_id' => $sourceRow->contractorId,
                        'cost_center_id' => $sourceRow->costCenterId,
                        'currency' => $metric->currency,
                        'bac_minor' => $metric->bacMinor,
                        'pv_minor' => $metric->pvMinor,
                        'ev_minor' => $metric->evMinor,
                        'ac_minor' => $metric->acMinor,
                        'approved_etc_minor' => $metric->approvedEtcMinor,
                        'sv_minor' => $metric->svMinor,
                        'cv_minor' => $metric->cvMinor,
                        'spi' => $metric->spi,
                        'cpi' => $metric->cpi,
                        'eac_minor' => $metric->eacMinor,
                        'payload' => [
                            'project_id' => $sourceRow->projectId,
                            'task_id' => $sourceRow->taskId,
                            'wbs_code' => $sourceRow->wbsCode,
                            'contractor_id' => $sourceRow->contractorId,
                            'cost_center_id' => $sourceRow->costCenterId,
                            'source_refs' => $sourceRow->sourceRefs,
                        ] + $metric->toArray(),
                        'source_refs' => $sourceRow->sourceRefs,
                    ]);
                }

                return $this->reference($query->scope, $query, $snapshot);
            });
        } catch (QueryException $exception) {
            $existing = ProjectControlSnapshot::query()
                ->where('organization_id', $identity->organizationId)
                ->where('query_hash', $query->queryHash->value)
                ->where('source_hash', $sourceHash->value)
                ->first();
            if ($existing !== null) {
                return $this->reference($query->scope, $query, $existing);
            }

            throw new InvalidArgumentException('project_control_snapshot_conflict', 0, $exception);
        }
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('project_control_scope_mismatch');
        }

        $snapshot = ProjectControlSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->orderByDesc('generated_at')
            ->first();
        if ($snapshot === null) {
            $source = $this->sources->assemble($scope, $query);

            return $this->capture(
                $source['identity'],
                $query,
                $source['rows'],
                $source['approved_by'],
                $source['approved_at'],
            );
        }

        return $this->reference($scope, $query, $snapshot);
    }

    private function reference(
        ReportScope $scope,
        ReportQuery $query,
        ProjectControlSnapshot $snapshot,
    ): ReportSnapshotRef {
        return new ReportSnapshotRef(
            'project_evm_control',
            (string) $snapshot->id,
            $scope,
            $query->definition->definitionHash,
            self::FORMULA_VERSION,
            new Sha256Hash((string) $snapshot->source_hash),
            new DateTimeImmutable($snapshot->generated_at->format(DATE_ATOM)),
            $snapshot->stale_at === null ? null : new DateTimeImmutable($snapshot->stale_at->format(DATE_ATOM)),
            (array) $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function rowSchema(): array
    {
        return array_map(
            static fn (string $id): array => ['id' => $id],
            [
                'wbs_code',
                'task_id',
                'currency',
                'bac_minor',
                'pv_minor',
                'ev_minor',
                'ac_minor',
                'sv_minor',
                'cv_minor',
                'spi',
                'cpi',
                'approved_etc_minor',
                'eac_minor',
                'vac_minor',
                'tcpi',
            ],
        );
    }
}
