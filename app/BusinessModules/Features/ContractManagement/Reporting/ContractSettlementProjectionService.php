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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ContractSettlementProjectionService
{
    public function __construct(
        private ContractSettlementCalculator $calculator,
        private SettlementAgingPolicy $agingPolicy,
        private ContractSettlementOwnerSource $ownerSource,
    ) {}

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== 'contract_settlement_exposure'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }

        $inputs = $this->ownerSource->read($scope, $query);
        if ($inputs === []) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $rows = [];
        $selectedInputs = [];
        foreach ($inputs as $input) {
            $result = $this->calculator->calculate($input, $this->agingPolicy);
            $agingFilter = $query->filters->values['aging_buckets']
                ?? $query->filters->values['aging_bucket']
                ?? null;
            if ($agingFilter !== null
                && ! in_array($result->agingBucket, is_array($agingFilter) ? $agingFilter : [$agingFilter], true)) {
                continue;
            }
            $selectedInputs[] = $input;
            $rows[] = [
                'row_key' => hash('sha256', $result->contractId.':'.$result->allocationId.':'.$result->direction.':'.$result->currency),
                'contract_id' => $result->contractId,
                'allocation_id' => $result->allocationId,
                'project_id' => $result->projectId,
                'party_id' => $result->partyId,
                'direction' => $result->direction,
                'currency' => $result->currency,
                'currency_source' => 'contract_payment_owner',
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
        if ($rows === []) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $generatedAt = $query->asOf;
        $staleAt = $generatedAt->add(new DateInterval('PT10M'));
        $snapshotId = (string) Str::ulid();
        $sourceWatermarkId = $this->sourceWatermark($selectedInputs);
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
            $selectedInputs,
        ): void {
            $timestamp = now();
            ContractSettlementSourceFact::query()->insertOrIgnore(array_map(
                static fn (ContractSettlementInput $input): array => [
                    'organization_id' => $scope->organizationId,
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'contract_id' => $input->contractId,
                    'allocation_id' => $input->allocationId,
                    'project_id' => $input->projectId,
                    'party_id' => $input->partyId,
                    'direction' => $input->direction,
                    'currency' => $input->currency,
                    'currency_source' => 'contract_payment_owner',
                    'effective_minor' => $input->effectiveMinor,
                    'accepted_minor' => $input->acceptedMinor,
                    'completed_cash_minor' => $input->cashMinor,
                    'due_at' => $input->dueAt,
                    'source_refs' => json_encode($input->sourceRefs, JSON_THROW_ON_ERROR),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ],
                $selectedInputs,
            ));
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
            watermarks: [
                'query_hash' => $query->queryHash->value,
                'as_of' => $query->asOf->format(DATE_ATOM),
                'aging_policy_version' => SettlementAgingPolicy::VERSION,
                'source_fact_id' => $sourceWatermarkId,
            ],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
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

    private function sourceWatermark(array $inputs): int
    {
        $ids = [];
        foreach ($inputs as $input) {
            foreach ($input->sourceRefs as $sourceRef) {
                if (ctype_digit((string) ($sourceRef['id'] ?? ''))) {
                    $ids[] = (int) $sourceRef['id'];
                }
            }
        }

        return $ids === [] ? 0 : max($ids);
    }
}
