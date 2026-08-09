<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportReconciliationStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardDecisionVersion;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardRow;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\SupplierAwardSnapshot;
use App\BusinessModules\Features\Procurement\Reporting\Award\Queries\SupplierAwardFilteredUniverse;
use App\Support\Reporting\OwnerSnapshotResultFactory;
use App\Support\Reporting\OwnerSnapshotSourceHash;
use App\Support\Reporting\OwnerSnapshotFirstWriter;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class SupplierAwardSnapshotMaterializer
{
    private const KIND = 'supplier_award_competitiveness';

    private const ROW_SCHEMA = [
        ['id' => 'row_key'],
        ['id' => 'selected_at'],
        ['id' => 'decision_id'],
        ['id' => 'decision_version'],
        ['id' => 'proposal_version_id'],
        ['id' => 'supplier_party_id'],
        ['id' => 'material_ids'],
        ['id' => 'currency'],
        ['id' => 'selected_amount_minor'],
        ['id' => 'cheapest_amount_minor'],
        ['id' => 'premium_minor'],
        ['id' => 'premium_ratio'],
        ['id' => 'participation_ratio'],
        ['id' => 'quality_warnings'],
    ];

    public function __construct(
        private SupplierAwardFormula $formula,
        private SupplierProposalComparabilityPolicy $comparability,
        private ComparableProposalVersionFactory $proposalFactory,
        private OwnerSnapshotSourceHash $sourceHashes,
        private OwnerSnapshotResultFactory $results,
        private SupplierAwardFilteredUniverse $universe,
    ) {}

    public function materialize(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        return OwnerSnapshotFirstWriter::run(
            $query,
            fn (): ReportSnapshotRef => $this->materializeLocked($context, $query, $progress),
        );
    }

    private function materializeLocked(
        ReportExecutionContext $context,
        ReportQuery $query,
        ReportProgress $progress,
    ): ReportSnapshotRef {
        $this->assertScope($context, $query);
        $organizationId = $context->scope->organizationId;
        $decisions = $this->universe->query($context, $query)
            ->orderBy('decision_id')
            ->orderBy('decision_version')
            ->get();
        $progress->advance(10);
        $versionIds = $decisions->flatMap(
            static fn (SupplierAwardDecisionVersion $decision): array => $decision->comparable_proposal_version_ids,
        )->unique()->values()->all();
        $versions = SupplierProposalVersion::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $versionIds)
            ->get()
            ->keyBy('id');
        $versionHashes = $versions->map(
            static fn (SupplierProposalVersion $version): string => hash(
                'sha256',
                CanonicalJson::encode([
                    'id' => $version->getKey(),
                    'snapshot' => $version->commercial_snapshot,
                    'dimension_hash' => $version->dimension_hash,
                    'supplier_party_id' => $version->supplier_party_id,
                    'supplier_request_id' => $version->supplier_request_id,
                    'supplier_proposal_id' => $version->supplier_proposal_id,
                    'version_number' => $version->version_number,
                ]),
            ),
        )->values()->all();
        $sourceHash = $this->sourceHashes->make(
            $query->canonicalJson,
            [
                ...$decisions->pluck('source_hash')->all(),
                ...$versionHashes,
            ],
        );
        $progress->advance(20);
        $existing = SupplierAwardSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SupplierAwardSnapshot) {
            $progress->advance(100);

            return $this->snapshotRef($query, $existing);
        }

        $snapshot = DB::transaction(function () use (
            $decisions,
            $organizationId,
            $progress,
            $query,
            $sourceHash,
            $versions,
        ) {
            $rows = [];
            $gapCount = 0;
            $premiumByCurrency = [];
            $decisionCount = $decisions->count();
            foreach ($decisions as $decisionIndex => $decision) {
                try {
                    $proposals = [];
                    foreach ($decision->comparable_proposal_version_ids as $versionId) {
                        $version = $versions->get($versionId);
                        if (! $version instanceof SupplierProposalVersion) {
                            throw new DomainException('Pinned proposal version is unavailable.');
                        }
                        $proposals[] = $this->proposalFactory->make($version);
                    }
                    $partition = $this->comparability->partition(
                        $proposals,
                        (int) $decision->selected_proposal_version_id,
                    );
                    $metric = $this->formula->calculate(
                        $decision->invited_supplier_ids,
                        $partition->comparable,
                        (int) $decision->selected_proposal_version_id,
                    );
                    if (! hash_equals((string) $decision->comparable_set_hash, $metric->comparableSetHash)) {
                        throw new DomainException('Pinned comparable proposal set does not match source versions.');
                    }
                    $selected = $versions->get($decision->selected_proposal_version_id);
                    if (! $selected instanceof SupplierProposalVersion) {
                        throw new DomainException('Selected proposal version is unavailable.');
                    }
                    $selectedData = $this->proposalFactory->make($selected);
                } catch (Throwable) {
                    $gapCount++;
                    $progress->advanceProportion($decisionIndex + 1, $decisionCount, 20, 85);

                    continue;
                }

                $premiumByCurrency[$selectedData->currency] = ($premiumByCurrency[$selectedData->currency] ?? 0)
                    + $metric->premiumMinor;
                $dimensionLines = is_array($decision->dimension_snapshot['lines'] ?? null)
                    ? $decision->dimension_snapshot['lines']
                    : [];
                $materialIds = collect($dimensionLines)
                    ->pluck('material_id')
                    ->filter(static fn (mixed $id): bool => is_int($id) && $id > 0)
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $rows[] = [
                    'organization_id' => $organizationId,
                    'row_key' => 'award_'.$decision->decision_id.'_'.$decision->decision_version,
                    'project_id' => $decision->project_id,
                    'material_ids' => $materialIds,
                    'decision_id' => $decision->decision_id,
                    'decision_version' => $decision->decision_version,
                    'proposal_version_id' => $selected->getKey(),
                    'supplier_party_id' => $selectedData->supplierId,
                    'selected_proposal_version_id' => $decision->selected_proposal_version_id,
                    'cheapest_proposal_version_id' => $decision->cheapest_proposal_version_id,
                    'median_proposal_version_id' => $decision->median_proposal_version_id,
                    'invited_count' => $metric->invitedCount,
                    'responded_count' => $metric->respondedCount,
                    'currency' => $selectedData->currency,
                    'selected_amount_minor' => $metric->selectedAmountMinor,
                    'cheapest_amount_minor' => $metric->cheapestAmountMinor,
                    'median_amount_minor' => $metric->medianAmountMinor,
                    'premium_minor' => $metric->premiumMinor,
                    'premium_ratio' => $metric->premiumRatio,
                    'median_variance_minor' => $metric->medianVarianceMinor,
                    'median_variance_ratio' => $metric->medianVarianceRatio,
                    'participation_ratio' => $metric->participationRatio,
                    'comparable_set_hash' => $metric->comparableSetHash,
                    'non_lowest_selected' => ! $decision->is_lowest_price_selected,
                    'decision_reason' => $decision->decision_reason,
                    'excluded_comparisons' => array_replace(
                        $decision->excluded_comparisons,
                        $partition->excludedReasonByProposalVersionId,
                    ),
                    'selected_at' => $decision->selected_at,
                    'quality_warnings' => [],
                ];
                $progress->advanceProportion($decisionIndex + 1, $decisionCount, 20, 85);
            }

            $progress->advance(90);
            $generatedAt = new DateTimeImmutable;
            ksort($premiumByCurrency, SORT_STRING);
            $totals = [
                'decision_count' => count($rows),
                'premium_by_currency' => $premiumByCurrency,
            ];
            $snapshot = SupplierAwardSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'query_hash' => $query->queryHash->value,
                'scope_hash' => hash('sha256', CanonicalJson::encode($query->scope->canonicalIdentity())),
                'source_hash' => $sourceHash,
                'formula_version' => $query->definition->formulaVersion,
                'source_schema_version' => $query->definition->sourceSchemaVersion,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->modify('+1 day'),
                'row_count' => count($rows),
                'eligible_count' => count($rows),
                'gap_count' => $gapCount,
                'quality_status' => $gapCount === 0 ? 'complete' : 'partial',
                'reconciliation_status' => 'not_applicable',
                'totals' => $totals,
            ]);
            $rowCount = count($rows);
            foreach ($rows as $rowIndex => $row) {
                $row['snapshot_id'] = $snapshot->getKey();
                SupplierAwardRow::query()->create($row);
                $progress->advanceProportion($rowIndex + 1, $rowCount, 90, 99);
            }
            $progress->advance(100);

            return $snapshot;
        }, 3);

        return $this->snapshotRef($query, $snapshot);
    }

    public function result(ReportExecutionContext $context, ReportSnapshotRef $snapshot): ReportResult
    {
        $record = SupplierAwardSnapshot::query()
            ->whereKey($snapshot->id)
            ->where('organization_id', $context->scope->organizationId)
            ->firstOrFail();

        return $this->results->result(
            $snapshot,
            (int) $record->row_count,
            (int) $record->gap_count,
            $record->totals,
            self::KIND,
            (string) $record->source_schema_version,
            $record->as_of->format(DATE_ATOM),
            self::ROW_SCHEMA,
            ['drill_down' => true, 'export' => true],
            ReportReconciliationStatus::NOT_APPLICABLE,
        );
    }

    private function snapshotRef(ReportQuery $query, SupplierAwardSnapshot $snapshot): ReportSnapshotRef
    {
        return $this->results->snapshot(
            self::KIND,
            (string) $snapshot->getKey(),
            $query->scope,
            $query->definition->definitionHash,
            (string) $snapshot->formula_version,
            (string) $snapshot->source_hash,
            new DateTimeImmutable((string) $snapshot->generated_at),
            $snapshot->stale_at === null ? null : new DateTimeImmutable((string) $snapshot->stale_at),
            ['as_of' => $snapshot->as_of->format(DATE_ATOM)],
        );
    }

    private function assertScope(ReportExecutionContext $context, ReportQuery $query): void
    {
        if ($context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()) {
            throw new DomainException('Report query scope does not match execution scope.');
        }
    }
}
