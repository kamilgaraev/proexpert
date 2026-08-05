<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Services;

use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\HoldingAllocationCheckpointSource;
use App\BusinessModules\Core\MultiOrganization\Reporting\DTO\IntercompanyFlowMetricRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationFactVersion;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAllocationProjectionGap;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowRow;
use App\BusinessModules\Core\MultiOrganization\Reporting\Models\IntercompanyContractFlowSnapshot;
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

final readonly class IntercompanyContractFlowSnapshotMaterializer
{
    public const CODE = 'intercompany_contract_flows';

    public function __construct(
        private IntercompanyContractFlowFormula $formula,
        private HoldingAllocationCheckpointSourceAssembler $sources,
        private HoldingAllocationFactProjector $projector,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertQuery($context, $query);
        $batch = $this->sources->assemble($context->scope, $query);
        if ($batch->gaps !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        foreach ($batch->sources as $source) {
            if (! $source instanceof HoldingAllocationCheckpointSource) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
            }
            $this->projector->persist($source->fact, $source->evidence);
        }
        $hierarchy = $batch->hierarchy;
        $coverageStartedAt = new DateTimeImmutable($batch->coverageStartedAt);
        $recordedCutoff = now()->toImmutable();
        $facts = $this->facts(
            $context,
            $query,
            $hierarchy->holdingId,
            $hierarchy->organizationIds,
            $recordedCutoff,
            $coverageStartedAt,
        )
            ->orderBy('id')
            ->get();
        $metrics = [];
        $groups = [];
        $sourcePayload = [[
            'holding_id' => $hierarchy->holdingId,
            'hierarchy_version' => $hierarchy->version,
            'source_schema_version' => $query->definition->sourceSchemaVersion,
            'source_watermark' => $batch->watermark,
        ]];
        $unknown = 0;

        foreach ($facts as $fact) {
            $currency = $fact->currency === null ? null : (string) $fact->currency;
            $sourceRefs = is_array($fact->source_refs) ? $fact->source_refs : [];
            $metric = null;
            $linkedEvidenceMissing = $fact->linked_parent_allocation_id !== null
                && ($fact->linked_incoming_minor === null || $fact->linked_outgoing_minor === null);
            if ($currency === null || $linkedEvidenceMissing) {
                $unknown++;
            } else {
                $metric = new IntercompanyFlowMetricRow(
                    (string) $fact->flow_class,
                    (int) $fact->amount_minor,
                    $currency,
                    $fact->linked_parent_allocation_id === null
                        ? null
                        : (int) $fact->linked_incoming_minor - (int) $fact->linked_outgoing_minor,
                    $sourceRefs,
                );
                $metrics[] = $metric;
            }

            $identity = [
                'project_id' => (int) $fact->project_id,
                'allocation_id' => (int) $fact->allocation_id,
                'counterparty_organization_id' => $fact->counterparty_organization_id === null ? null : (int) $fact->counterparty_organization_id,
                'currency' => $currency,
                'period_start' => $fact->recognized_on->format('Y-m-01'),
            ];
            $rowKey = hash('sha256', CanonicalJson::encode($identity));
            $groups[$rowKey] ??= [
                ...$identity,
                'metrics' => [],
                'source_refs' => [],
            ];
            if ($metric instanceof IntercompanyFlowMetricRow) {
                $groups[$rowKey]['metrics'][] = $metric;
            }
            $groups[$rowKey]['source_refs'] = $this->mergeSourceRefs(
                $groups[$rowKey]['source_refs'],
                $sourceRefs,
            );
            $sourcePayload[] = ['id' => (int) $fact->getKey(), 'hash' => (string) $fact->source_hash];
        }

        $rows = $this->projectionRows($groups);
        $totals = array_map(
            static fn ($aggregate): array => $aggregate->toArray(),
            $this->formula->totals($metrics),
        );
        $hierarchyGapCount = $facts
            ->filter(static fn (HoldingAllocationFactVersion $fact): bool => ! hash_equals(
                $hierarchy->version,
                (string) $fact->hierarchy_version,
            ))
            ->count();
        $projectionGaps = HoldingAllocationProjectionGap::query()
            ->where('holding_id', $hierarchy->holdingId)
            ->whereIn('organization_id', $hierarchy->organizationIds)
            ->whereIn('source_type', ['contract_checkpoint', 'contract'])
            ->where('monetary_basis', 'contracted')
            ->where('business_effective_at', '>=', $coverageStartedAt)
            ->where('business_effective_at', '<=', $query->asOf)
            ->where('recorded_at', '<=', $recordedCutoff)
            ->where(static fn (Builder $builder): Builder => $builder
                ->where(static fn (Builder $recorded): Builder => $recorded
                    ->whereNull('resolved_at')
                    ->orWhere('resolved_at', '>', $recordedCutoff))
                ->orWhere('resolved_business_effective_at', '>', $query->asOf))
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
        $qualityGapWatermark = hash('sha256', CanonicalJson::encode([
            'recorded_cutoff' => $recordedCutoff->format(DateTimeInterface::ATOM),
            'gaps' => $projectionGaps->map(static fn (HoldingAllocationProjectionGap $gap): array => [
                'id' => (int) $gap->getKey(),
                'source_hash' => (string) $gap->source_hash,
                'missing_fields' => $gap->missing_fields,
            ])->all(),
        ]));
        $unknown += $projectionGapCount;
        $totals['quality'] = [
            'eligible_count' => $facts->count() + $projectionGapCount,
            'unknown_currency_count' => $unknown,
            'hierarchy_gap_count' => $hierarchyGapCount,
            'projection_gap_count' => $projectionGapCount,
        ];
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($sourcePayload)));
        $snapshotId = (string) Str::ulid();
        $watermark = (string) ($facts->max('id') ?? 0);
        $hierarchyWatermark = $hierarchy->version;
        $sourceRef = new ReportSourceRef(
            'holding_allocations',
            'contract_allocation_checkpoint',
            'snapshot_'.strtolower($snapshotId),
            $query->definition->sourceSchemaVersion,
            'watermark_'.$watermark,
            count($sourcePayload),
            $sourceHash,
        );

        DB::transaction(function () use ($context, $query, $rows, $totals, $sourceHash, $snapshotId, $watermark, $hierarchyWatermark, $sourceRef, $unknown, $hierarchyGapCount, $hierarchy, $qualityGapWatermark, $projectionGapCount, $recordedCutoff): void {
            IntercompanyContractFlowSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $context->scope->organizationId,
                'holding_id' => $hierarchy->holdingId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'hierarchy_watermark' => $hierarchyWatermark,
                'allocation_watermark' => $watermark,
                'quality_gap_watermark' => $qualityGapWatermark,
                'quality_gap_count' => $projectionGapCount,
                'recorded_cutoff' => $recordedCutoff,
                'totals' => $totals,
                'source_refs' => [[
                    'source' => $sourceRef->source,
                    'snapshot_kind' => $sourceRef->snapshotKind,
                    'snapshot_id' => $sourceRef->snapshotId,
                    'schema_version' => $sourceRef->schemaVersion,
                    'watermark' => $sourceRef->watermark,
                    'row_count' => $sourceRef->rowCount,
                    'hash' => $sourceRef->hash->value,
                ]],
                'quality_status' => $unknown === 0 && $hierarchyGapCount === 0 && $rows !== []
                    ? ReportQualityStatus::COMPLETE->value
                    : ReportQualityStatus::PARTIAL->value,
                'freshness_status' => ReportFreshnessStatus::FRESH->value,
                'row_count' => count($rows),
                'generated_at' => $query->asOf,
                'stale_at' => null,
            ]);

            foreach ($rows as $row) {
                IntercompanyContractFlowRow::query()->create([
                    'organization_id' => $context->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    ...$row,
                ]);
            }
        });

        $progress->advance(100);

        return $this->ref($context, IntercompanyContractFlowSnapshot::query()->findOrFail($snapshotId));
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = $this->snapshot($context, $snapshot);
        $sourceRefs = array_map(
            static fn (array $source): ReportSourceRef => new ReportSourceRef(
                (string) $source['source'],
                (string) $source['snapshot_kind'],
                (string) $source['snapshot_id'],
                (string) $source['schema_version'],
                (string) $source['watermark'],
                (int) $source['row_count'],
                new Sha256Hash((string) $source['hash']),
            ),
            $record->source_refs,
        );

        return new ReportResult(
            new ReportResultMetadata($snapshot, (int) $record->row_count, $snapshot->generatedAt, $snapshot->staleAt),
            $record->totals,
            ReportFreshnessStatus::from((string) $record->freshness_status),
            $this->quality($record),
            new ReportProvenance('holding_allocation_facts', $sourceRefs, $snapshot->sourceHash, null),
            array_map(static fn (string $id): array => ['id' => $id], [
                'project',
                'counterparty',
                'period',
                'currency',
                'internal',
                'external',
                'unclassified',
                'total',
                'share',
                'linked_spread',
            ]),
            ['formats' => ['csv', 'xlsx'], 'drill_down' => true],
        );
    }

    public function snapshot(
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
    ): IntercompanyContractFlowSnapshot {
        $record = IntercompanyContractFlowSnapshot::query()
            ->where('organization_id', $context->scope->organizationId)
            ->whereKey($snapshot->id)
            ->first();
        if (! $record instanceof IntercompanyContractFlowSnapshot
            || $snapshot->kind !== self::CODE
            || ! hash_equals((string) $record->source_hash, $snapshot->sourceHash->value)
            || ! hash_equals((string) $record->definition_hash, $snapshot->definitionHash->value)
            || ! hash_equals((string) $record->formula_version, $snapshot->formulaVersion)
            || ! hash_equals((string) $record->query_hash, (string) ($snapshot->watermarks['query_hash'] ?? ''))) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SNAPSHOT_NOT_READY);
        }

        return $record;
    }

    public function quality(IntercompanyContractFlowSnapshot $snapshot): ReportQuality
    {
        $totals = $snapshot->getAttribute('totals');
        $eligible = is_array($totals)
            ? (int) ($totals['quality']['eligible_count'] ?? 0)
            : 0;
        $unknown = is_array($totals)
            ? (int) ($totals['quality']['unknown_currency_count'] ?? 0)
            : 0;
        $hierarchyGaps = is_array($totals)
            ? (int) ($totals['quality']['hierarchy_gap_count'] ?? 0)
            : 0;
        $empty = $eligible === 0;
        $unmatched = max($unknown, $hierarchyGaps);
        $matched = max(0, $eligible - $unmatched);

        return new ReportQuality(
            ReportQualityStatus::from((string) $snapshot->quality_status),
            new ReportCoverage((string) $matched, (string) $eligible, $empty ? null : number_format($matched / $eligible, 8, '.', '')),
            $empty
                ? [new ReportWarning('SOURCE_EMPTY', ReportWarningSeverity::CRITICAL, null, 0)]
                : array_values(array_filter([
                    $unknown === 0 ? null : new ReportWarning('UNKNOWN_FLOW_EVIDENCE', ReportWarningSeverity::CRITICAL, 'currency', $unknown),
                    $hierarchyGaps === 0 ? null : new ReportWarning('HIERARCHY_VERSION_MISSING', ReportWarningSeverity::CRITICAL, null, $hierarchyGaps),
                ])),
            $unmatched,
            $empty ? ReportReconciliationStatus::NOT_APPLICABLE : ($unmatched === 0 ? ReportReconciliationStatus::MATCHED : ReportReconciliationStatus::MISMATCH),
            $empty ? ['source_coverage'] : array_values(array_filter([
                $unknown === 0 ? null : 'currency',
                $hierarchyGaps === 0 ? null : 'hierarchy_version',
            ])),
            $empty ? ['owner_snapshot'] : array_values(array_filter([
                $unknown === 0 ? null : 'unknown_currency',
                $hierarchyGaps === 0 ? null : 'unknown_hierarchy',
            ])),
        );
    }

    private function facts(
        ReportExecutionContext $context,
        ReportQuery $query,
        int $holdingId,
        array $organizationIds,
        DateTimeInterface $recordedCutoff,
        DateTimeInterface $coverageStartedAt,
    ): Builder {
        $builder = HoldingAllocationFactVersion::query()
            ->where('source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
            ->where('holding_id', $holdingId)
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('source_type', ['contract_checkpoint', 'contract'])
            ->where('monetary_basis', 'contracted')
            ->whereIn('contributor_organization_id', $organizationIds)
            ->where('business_effective_at', '>=', $coverageStartedAt)
            ->where('business_effective_at', '<=', $query->asOf)
            ->where('recorded_at', '<=', $recordedCutoff)
            ->whereNotExists(function (QueryBuilder $newer) use ($query, $recordedCutoff): void {
                $newer
                    ->selectRaw('1')
                    ->from('holding_allocation_fact_versions as newer_fact')
                    ->whereColumn('newer_fact.holding_id', 'holding_allocation_fact_versions.holding_id')
                    ->whereColumn('newer_fact.organization_id', 'holding_allocation_fact_versions.organization_id')
                    ->whereColumn('newer_fact.allocation_id', 'holding_allocation_fact_versions.allocation_id')
                    ->whereColumn('newer_fact.monetary_basis', 'holding_allocation_fact_versions.monetary_basis')
                    ->where('newer_fact.source_schema_version', HoldingAllocationFactVersion::SOURCE_SCHEMA_VERSION)
                    ->whereIn('newer_fact.source_type', ['contract_checkpoint', 'contract'])
                    ->where('newer_fact.business_effective_at', '<=', $query->asOf)
                    ->where('newer_fact.recorded_at', '<=', $recordedCutoff)
                    ->where(static fn (QueryBuilder $tuple): QueryBuilder => $tuple
                        ->whereColumn('newer_fact.business_effective_at', '>', 'holding_allocation_fact_versions.business_effective_at')
                        ->orWhere(static fn (QueryBuilder $sameBusiness): QueryBuilder => $sameBusiness
                            ->whereColumn('newer_fact.business_effective_at', 'holding_allocation_fact_versions.business_effective_at')
                            ->where(static fn (QueryBuilder $recorded): QueryBuilder => $recorded
                                ->whereColumn('newer_fact.recorded_at', '>', 'holding_allocation_fact_versions.recorded_at')
                                ->orWhere(static fn (QueryBuilder $sameRecorded): QueryBuilder => $sameRecorded
                                    ->whereColumn('newer_fact.recorded_at', 'holding_allocation_fact_versions.recorded_at')
                                    ->where(static fn (QueryBuilder $tieBreak): QueryBuilder => $tieBreak
                                        ->whereColumn('newer_fact.source_version', '>', 'holding_allocation_fact_versions.source_version')
                                        ->orWhere(static fn (QueryBuilder $sameVersion): QueryBuilder => $sameVersion
                                            ->whereColumn('newer_fact.source_version', 'holding_allocation_fact_versions.source_version')
                                            ->whereColumn('newer_fact.id', '>', 'holding_allocation_fact_versions.id')))))));
            });
        if ($context->scope->projectIds !== []) {
            $builder->whereIn('project_id', $context->scope->projectIds);
        }
        $filters = $query->filters->values;
        foreach ([
            'project_ids' => 'project_id',
            'organization_ids' => 'contributor_organization_id',
            'counterparty_ids' => 'counterparty_organization_id',
            'contract_ids' => 'contract_id',
            'work_type_categories' => 'work_type_category',
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

    private function projectionRows(array $groups): array
    {
        $rows = [];

        foreach ($groups as $rowKey => $group) {
            $metrics = $group['metrics'];
            if ($metrics === []) {
                $rows[] = [
                    'project_id' => $group['project_id'],
                    'allocation_id' => $group['allocation_id'],
                    'counterparty_organization_id' => $group['counterparty_organization_id'],
                    'currency' => null,
                    'period_start' => $group['period_start'],
                    'internal_minor' => 0,
                    'external_minor' => 0,
                    'unclassified_minor' => 0,
                    'total_minor' => 0,
                    'internal_share' => null,
                    'external_share' => null,
                    'unclassified_share' => null,
                    'linked_spread_minor' => null,
                    'row_key' => $rowKey,
                    'source_refs' => $group['source_refs'],
                ];

                continue;
            }

            $aggregate = $this->formula->aggregate($metrics);
            $rows[] = [
                'project_id' => $group['project_id'],
                'allocation_id' => $group['allocation_id'],
                'counterparty_organization_id' => $group['counterparty_organization_id'],
                'currency' => $aggregate->currency,
                'period_start' => $group['period_start'],
                'internal_minor' => $aggregate->internalMinor,
                'external_minor' => $aggregate->externalMinor,
                'unclassified_minor' => $aggregate->unclassifiedMinor,
                'total_minor' => $aggregate->totalMinor,
                'internal_share' => $aggregate->internalShare,
                'external_share' => $aggregate->externalShare,
                'unclassified_share' => $aggregate->unclassifiedShare,
                'linked_spread_minor' => $aggregate->linkedSpreadMinor,
                'row_key' => $rowKey,
                'source_refs' => $group['source_refs'],
            ];
        }

        return $rows;
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

    private function ref(
        ReportExecutionContext $context,
        IntercompanyContractFlowSnapshot $record,
    ): ReportSnapshotRef {
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
                'quality_gaps' => (string) $record->quality_gap_watermark,
                'quality_gap_count' => (int) $record->quality_gap_count,
                'recorded_cutoff' => $record->recorded_cutoff?->format(DateTimeInterface::ATOM),
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }
}
