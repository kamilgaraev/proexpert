<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Services\Reports\SettlementAgingPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\DTO\ContractSettlementInput;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementExposureSnapshot;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementSourceFact;
use DateInterval;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ContractSettlementProjectionService
{
    public function __construct(
        private ContractSettlementCalculator $calculator,
        private SettlementAgingPolicy $agingPolicy,
    ) {
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== 'contract_settlement_exposure'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }

        $sourceQuery = ContractSettlementSourceFact::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf);
        if ($scope->projectIds !== []) {
            $sourceQuery->whereIn('project_id', $scope->projectIds);
        }
        $this->applyResourceScope($sourceQuery, $scope);
        $facts = $sourceQuery
            ->orderBy('contract_id')
            ->orderBy('allocation_id')
            ->orderBy('id')
            ->get();
        if ($facts->isEmpty()) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $rows = [];
        foreach ($facts->groupBy(
            static fn (ContractSettlementSourceFact $fact): string => implode(':', [
                $fact->contract_id,
                $fact->allocation_id,
                $fact->direction,
                $fact->currency,
            ]),
        ) as $group) {
            $currencies = $group->pluck('currency')->unique()->values();
            if ($currencies->count() !== 1) {
                throw new DomainException('contract_settlement_currency_mismatch');
            }
            $first = $group->first();
            $input = new ContractSettlementInput(
                contractId: (int) $first->contract_id,
                allocationId: (int) $first->allocation_id,
                projectId: $first->project_id === null ? null : (int) $first->project_id,
                partyId: $first->party_id === null ? null : (int) $first->party_id,
                direction: (string) $first->direction,
                currency: (string) $first->currency,
                effectiveMinor: (int) $group->sum('effective_minor'),
                acceptedMinor: (int) $group->sum('accepted_minor'),
                cashMinor: (int) $group->sum('completed_cash_minor'),
                dueAt: $first->due_at?->toDateTimeImmutable(),
                asOf: $query->asOf,
                sourceRefs: $group->map(static fn (ContractSettlementSourceFact $fact): array => [
                    'type' => (string) $fact->source_type,
                    'id' => (string) $fact->source_id,
                    'version' => (int) $fact->source_version,
                    'hash' => (string) $fact->source_hash,
                ])->values()->all(),
            );
            $result = $this->calculator->calculate($input, $this->agingPolicy);
            $rows[] = [
                'row_key' => hash('sha256', $result->contractId.':'.$result->allocationId.':'.$result->direction.':'.$result->currency),
                'contract_id' => $result->contractId,
                'allocation_id' => $result->allocationId,
                'project_id' => $result->projectId,
                'party_id' => $result->partyId,
                'direction' => $result->direction,
                'currency' => $result->currency,
                'currency_source' => (string) $first->currency_source,
                'effective_minor' => $result->effectiveMinor,
                'accepted_minor' => $result->acceptedMinor,
                'cash_minor' => $result->cashMinor,
                'settlement_minor' => $result->settlementMinor,
                'unperformed_exposure_minor' => $result->unperformedExposureMinor,
                'unpaid_exposure_minor' => $result->unpaidExposureMinor,
                'aging_bucket' => $result->agingBucket,
                'source_refs' => $result->sourceRefs,
            ];
        }

        $generatedAt = $query->asOf;
        $staleAt = $generatedAt->add(new DateInterval('PT10M'));
        $snapshotId = (string) Str::ulid();
        $sourceWatermarkId = (int) $facts->max('id');
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'formula_version' => ContractSettlementCalculator::FORMULA_VERSION,
            'aging_policy_version' => SettlementAgingPolicy::VERSION,
            'query_hash' => $query->queryHash->value,
            'source_watermark_id' => $sourceWatermarkId,
            'rows' => $rows,
        ])));
        $totals = $this->totals($rows);

        DB::transaction(function () use (
            $scope,
            $query,
            $snapshotId,
            $sourceHash,
            $generatedAt,
            $staleAt,
            $sourceWatermarkId,
            $rows,
            $totals,
        ): void {
            $snapshot = ContractSettlementExposureSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => ContractSettlementCalculator::FORMULA_VERSION,
                'aging_policy_version' => SettlementAgingPolicy::VERSION,
                'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
                'source_watermark_id' => $sourceWatermarkId,
                'row_count' => count($rows),
                'totals' => $totals,
                'coverage_numerator' => count($rows),
                'coverage_denominator' => count($rows),
                'quality_status' => 'complete',
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $snapshot->rows()->createMany(array_map(
                    static fn (array $row): array => [
                        ...$row,
                        'organization_id' => $scope->organizationId,
                    ],
                    $chunk,
                ));
            }
        });

        return new ReportSnapshotRef(
            kind: 'contract_settlement_exposure',
            id: $snapshotId,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: ContractSettlementCalculator::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: ['source_fact_id' => $sourceWatermarkId],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function applyResourceScope(Builder $query, ReportScope $scope): void
    {
        $contractIds = [];
        $allocationIds = [];
        foreach ($scope->resources as $resource) {
            if ($resource->kind === 'contract') {
                $contractIds[] = $resource->id;
            }
            if ($resource->kind === 'contract_allocation') {
                $allocationIds[] = $resource->id;
            }
        }
        if ($contractIds !== []) {
            $query->whereIn('contract_id', $contractIds);
        }
        if ($allocationIds !== []) {
            $query->whereIn('allocation_id', $allocationIds);
        }
    }

    private function totals(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totals[$currency] ??= [
                'effective_minor' => 0,
                'accepted_minor' => 0,
                'cash_minor' => 0,
                'settlement_minor' => 0,
                'unperformed_exposure_minor' => 0,
                'unpaid_exposure_minor' => 0,
            ];
            foreach (array_keys($totals[$currency]) as $field) {
                $totals[$currency][$field] += $row[$field];
            }
        }
        ksort($totals);

        return $totals;
    }
}
