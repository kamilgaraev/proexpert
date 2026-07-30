<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationFact;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingHierarchySnapshot;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingPerformanceMetricRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPerformanceRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingPerformanceSnapshot;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCoverage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProvenance;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuality;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResultMetadata;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWarning;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportFreshnessStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportQualityStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportWarningSeverity;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class HoldingPerformanceSnapshotMaterializer
{
    public const CODE = 'holding_performance';

    public function __construct(
        private HoldingPerformanceFormula $formula,
        private HoldingHierarchyResolver $hierarchies = new HoldingHierarchyResolver,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query);
        $hierarchy = $this->hierarchy($context);
        $recordedCutoff = now()->toImmutable();
        $facts = $this->facts(
            $context,
            $query,
            $hierarchy->holdingId,
            $hierarchy->organizationIds,
            $recordedCutoff,
        )
            ->orderBy('id')
            ->get();
        $metricRows = [];
        $sourcePayload = [[
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'source_schema_version' => $query->definition->sourceSchemaVersion,
        ]];

        foreach ($facts as $factRecord) {
            $fact = $this->fact($factRecord);
            $metricRows[] = $this->formula->row($fact);
            $sourcePayload[] = [
                'id' => (int) $factRecord->getKey(),
                'source_hash' => (string) $factRecord->source_hash,
                'source_key' => $fact->sourceKey(),
            ];
        }

        $totals = $this->formula->totals($metricRows);
        $projectionRows = $this->projectionRows($metricRows);
        $hierarchyGapCount = $facts
            ->filter(static fn (HoldingAllocationFactVersion $fact): bool => ! hash_equals(
                $hierarchy->version,
                (string) $fact->hierarchy_version,
            ))
            ->count();
        $projectionGaps = HoldingAllocationProjectionGap::query()
            ->where('holding_id', $hierarchy->holdingId)
            ->whereIn('organization_id', $hierarchy->organizationIds)
            ->where('business_effective_at', '<=', $query->asOf)
            ->where('recorded_at', '<=', $recordedCutoff)
            ->where(static fn (Builder $builder): Builder => $builder
                ->whereNull('resolved_at')
                ->orWhere('resolved_at', '>', $recordedCutoff))
            ->whereNotExists(function (QueryBuilder $newer) use ($query, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_allocation_projection_gaps as newer_gap')
                    ->whereColumn('newer_gap.holding_id', 'holding_allocation_projection_gaps.holding_id')
                    ->whereColumn('newer_gap.organization_id', 'holding_allocation_projection_gaps.organization_id')
                    ->whereColumn('newer_gap.source_type', 'holding_allocation_projection_gaps.source_type')
                    ->whereColumn('newer_gap.source_id', 'holding_allocation_projection_gaps.source_id')
                    ->whereColumn('newer_gap.source_version', 'holding_allocation_projection_gaps.source_version')
                    ->whereColumn('newer_gap.monetary_basis', 'holding_allocation_projection_gaps.monetary_basis')
                    ->where('newer_gap.business_effective_at', '<=', $query->asOf)
                    ->where('newer_gap.recorded_at', '<=', $recordedCutoff)
                    ->where(static fn (QueryBuilder $tuple): QueryBuilder => $tuple
                        ->whereColumn(
                            'newer_gap.business_effective_at',
                            '>',
                            'holding_allocation_projection_gaps.business_effective_at',
                        )
                        ->orWhere(static fn (QueryBuilder $sameBusiness): QueryBuilder => $sameBusiness
                            ->whereColumn(
                                'newer_gap.business_effective_at',
                                'holding_allocation_projection_gaps.business_effective_at',
                            )
                            ->where(static fn (QueryBuilder $recorded): QueryBuilder => $recorded
                                ->whereColumn(
                                    'newer_gap.recorded_at',
                                    '>',
                                    'holding_allocation_projection_gaps.recorded_at',
                                )
                                ->orWhere(static fn (QueryBuilder $sameRecorded): QueryBuilder => $sameRecorded
                                    ->whereColumn(
                                        'newer_gap.recorded_at',
                                        'holding_allocation_projection_gaps.recorded_at',
                                    )
                                    ->whereColumn(
                                        'newer_gap.id',
                                        '>',
                                        'holding_allocation_projection_gaps.id',
                                    )))));
            })
            ->orderBy('id')
            ->get(['id', 'source_hash', 'missing_fields']);
        $projectionGapCount = $projectionGaps->count();
        foreach ($projectionGaps as $gap) {
            $sourcePayload[] = [
                'gap_id' => (int) $gap->getKey(),
                'source_hash' => (string) $gap->source_hash,
                'missing_fields' => $gap->missing_fields,
            ];
        }
        $qualityGapPayload = $projectionGaps
            ->map(static fn (HoldingAllocationProjectionGap $gap): array => [
                'id' => (int) $gap->getKey(),
                'source_hash' => (string) $gap->source_hash,
                'missing_fields' => $gap->missing_fields,
            ])
            ->all();
        $qualityGapWatermark = hash('sha256', CanonicalJson::encode([
            'recorded_cutoff' => $recordedCutoff->format(DateTimeInterface::ATOM),
            'gaps' => $qualityGapPayload,
        ]));
        $totals['quality']['hierarchy_gap_count'] = $hierarchyGapCount;
        $totals['quality']['projection_gap_count'] = $projectionGapCount;
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($sourcePayload)));
        $snapshotId = (string) Str::ulid();
        $generatedAt = $query->asOf;
        $unknown = (int) $totals['quality']['unknown_currency_count'];
        $watermarks = [
            'allocation' => (string) ($facts->where('monetary_basis', 'contracted')->max('id') ?? 0),
            'act' => (string) ($facts->where('monetary_basis', 'accepted_accrual')->max('id') ?? 0),
            'payment' => (string) ($facts->where('monetary_basis', 'cash')->max('id') ?? 0),
            'quality_gaps' => $qualityGapWatermark,
            'recorded_cutoff' => $recordedCutoff->format(DateTimeInterface::ATOM),
        ];
        $sourceRef = $this->sourceRef(
            $snapshotId,
            count($sourcePayload),
            substr(hash('sha256', CanonicalJson::encode($watermarks)), 0, 40),
            $sourceHash,
        );

        DB::transaction(function () use ($context, $query, $snapshotId, $generatedAt, $sourceHash, $watermarks, $totals, $unknown, $hierarchyGapCount, $projectionGapCount, $sourceRef, $projectionRows, $hierarchy, $qualityGapWatermark, $recordedCutoff): void {
            HoldingPerformanceSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $context->scope->organizationId,
                'holding_id' => $hierarchy->holdingId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'hierarchy_watermark' => $hierarchy->version,
                'allocation_watermark' => $watermarks['allocation'],
                'act_watermark' => $watermarks['act'],
                'payment_watermark' => $watermarks['payment'],
                'quality_gap_watermark' => $qualityGapWatermark,
                'quality_gap_count' => $projectionGapCount,
                'recorded_cutoff' => $recordedCutoff,
                'totals' => $totals,
                'source_refs' => [$this->serializeSourceRef($sourceRef)],
                'quality_status' => $unknown === 0 && $hierarchyGapCount === 0 && $projectionGapCount === 0 && $projectionRows !== []
                    ? ReportQualityStatus::COMPLETE->value
                    : ReportQualityStatus::PARTIAL->value,
                'freshness_status' => ReportFreshnessStatus::FRESH->value,
                'row_count' => count($projectionRows),
                'generated_at' => $generatedAt,
                'stale_at' => null,
            ]);

            foreach ($projectionRows as $row) {
                HoldingPerformanceRow::query()->create([
                    'organization_id' => $context->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    'contributor_organization_id' => $row->contributorOrganizationId,
                    'project_id' => $row->projectId,
                    'currency' => $row->currency,
                    'period_start' => $row->periodStart,
                    'monetary_basis' => $row->monetaryBasis,
                    'contracted_minor' => $row->contractedMinor,
                    'accepted_accrual_minor' => $row->acceptedAccrualMinor,
                    'cash_minor' => $row->cashMinor,
                    'row_key' => $row->rowKey,
                    'source_refs' => $row->sourceRefs,
                ]);
            }
        });

        $progress->advance(100);

        return $this->ref($context, HoldingPerformanceSnapshot::query()->findOrFail($snapshotId));
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $quality = $this->quality($record);
        $sourceRefs = array_map($this->hydrateSourceRef(...), $record->source_refs);

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            $record->totals,
            ReportFreshnessStatus::from((string) $record->freshness_status),
            $quality,
            new ReportProvenance('holding_allocation_facts', $sourceRefs, $snapshot->sourceHash, null),
            array_map(static fn (string $id): array => ['id' => $id], [
                'organization',
                'project',
                'period',
                'currency',
                'basis',
                'contracted',
                'accepted_accrual',
                'cash',
            ]),
            ['formats' => ['csv', 'xlsx'], 'drill_down' => true],
        );
    }

    public function snapshot(ReportExecutionContext $context, ReportSnapshotRef $snapshot): HoldingPerformanceSnapshot
    {
        $record = HoldingPerformanceSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->first();
        if (! $record instanceof HoldingPerformanceSnapshot
            || $snapshot->kind !== self::CODE
            || ! hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)
            || ! hash_equals((string) $record->definition_hash, $snapshot->definitionHash->value)
            || ! hash_equals((string) $record->formula_version, $snapshot->formulaVersion)
            || ! hash_equals((string) $record->query_hash, (string) ($snapshot->watermarks['query_hash'] ?? ''))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    public function quality(HoldingPerformanceSnapshot $snapshot): ReportQuality
    {
        $totals = $snapshot->getAttribute('totals');
        $unknown = is_array($totals)
            ? (int) ($totals['quality']['unknown_currency_count'] ?? 0)
            : 0;
        $hierarchyGaps = is_array($totals)
            ? (int) ($totals['quality']['hierarchy_gap_count'] ?? 0)
            : 0;
        $projectionGaps = is_array($totals)
            ? (int) ($totals['quality']['projection_gap_count'] ?? 0)
            : 0;
        $eligible = is_array($totals)
            ? (int) ($totals['quality']['eligible_count'] ?? 0)
            : 0;
        $eligible += $projectionGaps;
        $empty = $eligible === 0;
        $unmatched = $unknown + $hierarchyGaps + $projectionGaps;
        $matched = max(0, $eligible - $unmatched);

        return new ReportQuality(
            ReportQualityStatus::from((string) $snapshot->quality_status),
            new ReportCoverage((string) $matched, (string) $eligible, $empty ? null : number_format($matched / $eligible, 8, '.', '')),
            $empty
                ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)]
                : array_values(array_filter([
                    $unknown === 0 ? null : new ReportWarning('UNKNOWN_CURRENCY', ReportWarningSeverity::CRITICAL, 'currency', $unknown),
                    $hierarchyGaps === 0 ? null : new ReportWarning('HIERARCHY_VERSION_MISSING', ReportWarningSeverity::CRITICAL, null, $hierarchyGaps),
                    $projectionGaps === 0 ? null : new ReportWarning('SOURCE_FACT_GAP', ReportWarningSeverity::CRITICAL, null, $projectionGaps),
                ])),
            $unmatched,
            $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ($unmatched === 0 ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH),
            $empty ? ['source_coverage'] : array_values(array_filter([
                $unknown === 0 ? null : 'currency',
                $hierarchyGaps === 0 ? null : 'hierarchy_version',
                $projectionGaps === 0 ? null : 'source_facts',
            ])),
            $empty ? ['owner_snapshot'] : array_values(array_filter([
                $unknown === 0 ? null : 'unknown_currency',
                $hierarchyGaps === 0 ? null : 'unknown_hierarchy',
                $projectionGaps === 0 ? null : 'projection_gaps',
            ])),
        );
    }

    private function facts(
        ReportExecutionContext $context,
        ReportQuery $query,
        int $holdingId,
        array $organizationIds,
        DateTimeInterface $recordedCutoff,
    ): Builder {
        $builder = HoldingAllocationFactVersion::query()
            ->where('holding_id', $holdingId)
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('contributor_organization_id', $organizationIds)
            ->where('business_effective_at', '<=', $query->asOf)
            ->where('recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (QueryBuilder $newer) use ($query, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_allocation_fact_versions as newer_fact')
                    ->whereColumn('newer_fact.holding_id', 'holding_allocation_fact_versions.holding_id')
                    ->whereColumn('newer_fact.organization_id', 'holding_allocation_fact_versions.organization_id')
                    ->whereColumn('newer_fact.source_type', 'holding_allocation_fact_versions.source_type')
                    ->whereColumn('newer_fact.source_id', 'holding_allocation_fact_versions.source_id')
                    ->whereColumn('newer_fact.monetary_basis', 'holding_allocation_fact_versions.monetary_basis')
                    ->where('newer_fact.business_effective_at', '<=', $query->asOf)
                    ->where('newer_fact.recorded_at', '<=', $recordedCutoff)
                    ->where(static fn (QueryBuilder $tuple): QueryBuilder => $tuple
                        ->whereColumn(
                            'newer_fact.business_effective_at',
                            '>',
                            'holding_allocation_fact_versions.business_effective_at',
                        )
                        ->orWhere(static fn (QueryBuilder $sameBusiness): QueryBuilder => $sameBusiness
                            ->whereColumn(
                                'newer_fact.business_effective_at',
                                'holding_allocation_fact_versions.business_effective_at',
                            )
                            ->where(static fn (QueryBuilder $recorded): QueryBuilder => $recorded
                                ->whereColumn(
                                    'newer_fact.recorded_at',
                                    '>',
                                    'holding_allocation_fact_versions.recorded_at',
                                )
                                ->orWhere(static fn (QueryBuilder $sameRecorded): QueryBuilder => $sameRecorded
                                    ->whereColumn(
                                        'newer_fact.recorded_at',
                                        'holding_allocation_fact_versions.recorded_at',
                                    )
                                    ->where(static fn (QueryBuilder $tieBreak): QueryBuilder => $tieBreak
                                        ->whereColumn(
                                            'newer_fact.source_version',
                                            '>',
                                            'holding_allocation_fact_versions.source_version',
                                        )
                                        ->orWhere(static fn (QueryBuilder $sameVersion): QueryBuilder => $sameVersion
                                            ->whereColumn(
                                                'newer_fact.source_version',
                                                'holding_allocation_fact_versions.source_version',
                                            )
                                            ->whereColumn(
                                                'newer_fact.id',
                                                '>',
                                                'holding_allocation_fact_versions.id',
                                            )))))));
            });
        if ($context->scope->projectIds !== []) {
            $builder->whereIn('project_id', $context->scope->projectIds);
        }

        $filters = $query->filters->values;
        foreach ([
            'organization_ids' => 'contributor_organization_id',
            'project_ids' => 'project_id',
            'currencies' => 'currency',
        ] as $filter => $column) {
            if (isset($filters[$filter]) && is_array($filters[$filter]) && $filters[$filter] !== []) {
                $builder->whereIn($column, $filters[$filter]);
            }
        }
        if (isset($filters['period_from']) && is_string($filters['period_from'])) {
            $builder->whereDate('recognized_on', '>=', $filters['period_from']);
        }
        if (isset($filters['period_to']) && is_string($filters['period_to'])) {
            $builder->whereDate('recognized_on', '<=', $filters['period_to']);
        }

        return $builder;
    }

    private function hierarchy(ReportExecutionContext $context): HoldingHierarchySnapshot
    {
        $hierarchy = $this->hierarchies->resolve($context->scope->organizationId);
        if ($hierarchy->holdingId !== $context->scope->organizationId
            || array_diff($context->scope->holdingOrganizationIds, $hierarchy->organizationIds) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return $hierarchy;
    }

    private function fact(HoldingAllocationFactVersion $record): HoldingAllocationFact
    {
        return new HoldingAllocationFact(
            (int) $record->organization_id,
            (int) $record->holding_id,
            (string) $record->hierarchy_version,
            (int) $record->contributor_organization_id,
            $record->counterparty_organization_id === null ? null : (int) $record->counterparty_organization_id,
            (int) $record->project_id,
            (int) $record->contract_id,
            (int) $record->allocation_id,
            $record->linked_parent_allocation_id === null ? null : (int) $record->linked_parent_allocation_id,
            $record->linked_incoming_minor === null ? null : (int) $record->linked_incoming_minor,
            $record->linked_outgoing_minor === null ? null : (int) $record->linked_outgoing_minor,
            (string) $record->source_type,
            (int) $record->source_id,
            (int) $record->source_version,
            (string) $record->monetary_basis,
            (int) $record->amount_minor,
            $record->currency === null ? null : (string) $record->currency,
            (string) $record->currency_source,
            (string) $record->tax_basis,
            $record->recognized_on->format('Y-m-d'),
            (string) $record->flow_class,
            $record->source_refs,
        );
    }

    private function projectionRows(array $metricRows): array
    {
        $rows = [];

        foreach ($metricRows as $metric) {
            $identity = implode(':', [
                $metric->contributorOrganizationId,
                $metric->projectId,
                $metric->currency ?? 'unknown',
                $metric->periodStart,
                $metric->monetaryBasis,
            ]);
            $rowKey = hash('sha256', $identity);

            if (! isset($rows[$rowKey])) {
                $rows[$rowKey] = new HoldingPerformanceMetricRow(
                    $metric->organizationId,
                    $metric->holdingId,
                    $metric->contributorOrganizationId,
                    $metric->projectId,
                    $metric->currency,
                    $metric->periodStart,
                    $metric->monetaryBasis,
                    0,
                    0,
                    0,
                    $rowKey,
                    [],
                );
            }

            $current = $rows[$rowKey];
            $rows[$rowKey] = new HoldingPerformanceMetricRow(
                $current->organizationId,
                $current->holdingId,
                $current->contributorOrganizationId,
                $current->projectId,
                $current->currency,
                $current->periodStart,
                $current->monetaryBasis,
                $current->contractedMinor + $metric->contractedMinor,
                $current->acceptedAccrualMinor + $metric->acceptedAccrualMinor,
                $current->cashMinor + $metric->cashMinor,
                $rowKey,
                $this->mergeSourceRefs($current->sourceRefs, $metric->sourceRefs),
            );
        }

        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    private function mergeSourceRefs(array ...$groups): array
    {
        $refs = [];

        foreach ($groups as $group) {
            foreach ($group as $ref) {
                $refs[hash('sha256', CanonicalJson::encode($ref))] = $ref;
            }
        }

        ksort($refs, SORT_STRING);

        return array_values($refs);
    }

    private function assertQuery(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($query->definition->code !== self::CODE
            || $query->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
    }

    private function ref(ReportExecutionContext $context, HoldingPerformanceSnapshot $record): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            self::CODE,
            (string) $record->getKey(),
            $context->scope,
            new Sha256Hash((string) $record->definition_hash),
            (string) $record->formula_version,
            new Sha256Hash((string) $record->source_hash),
            new DateTimeImmutable((string) $record->generated_at),
            null,
            [
                'query_hash' => (string) $record->query_hash,
                'hierarchy' => (string) $record->hierarchy_watermark,
                'allocation' => (string) $record->allocation_watermark,
                'act' => (string) $record->act_watermark,
                'payment' => (string) $record->payment_watermark,
                'quality_gaps' => (string) $record->quality_gap_watermark,
                'quality_gap_count' => (int) $record->quality_gap_count,
                'recorded_cutoff' => $record->recorded_cutoff?->format(DateTimeInterface::ATOM),
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function sourceRef(string $snapshotId, int $count, string $watermark, Sha256Hash $hash): ReportSourceRef
    {
        return new ReportSourceRef(
            'holding_allocations',
            'holding_facts',
            'snapshot_'.strtolower($snapshotId),
            'holding_facts_v1',
            'watermark_'.$watermark,
            $count,
            $hash,
        );
    }

    private function serializeSourceRef(ReportSourceRef $source): array
    {
        return [
            'source' => $source->source,
            'snapshot_kind' => $source->snapshotKind,
            'snapshot_id' => $source->snapshotId,
            'schema_version' => $source->schemaVersion,
            'watermark' => $source->watermark,
            'row_count' => $source->rowCount,
            'hash' => $source->hash->value,
        ];
    }

    private function hydrateSourceRef(array $source): ReportSourceRef
    {
        return new ReportSourceRef(
            (string) $source['source'],
            (string) $source['snapshot_kind'],
            (string) $source['snapshot_id'],
            (string) $source['schema_version'],
            (string) $source['watermark'],
            (int) $source['row_count'],
            new Sha256Hash((string) $source['hash']),
        );
    }
}
