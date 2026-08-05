<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
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
use App\Enums\CurrencyCode;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ContractSettlementProjectionService
{
    private const SOURCE_RESOURCE_TYPES = [
        'contract',
        'contract_allocation',
        'contract_performance_act',
        'payment_document',
        'payment_transaction',
    ];

    public function __construct(
        private ContractSettlementCalculator $calculator,
        private SettlementAgingPolicy $agingPolicy,
        private ContractSettlementOwnerSource $ownerSource,
        private FinanceSourceAccessPolicy $sourceAccess,
    ) {}

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== 'contract_settlement_exposure'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }

        $inputs = $this->persistedInputs($scope, $query);
        if ($inputs === []) {
            $inputs = $this->ownerSource->read($scope, $query);
        }
        $inputs = array_values(array_filter(
            $inputs,
            fn (ContractSettlementInput $input): bool => $this->sourceAccess->allowsAggregate(
                $scope,
                $input->sourceRefs,
                self::SOURCE_RESOURCE_TYPES,
            ),
        ));
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
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        $existing = ContractSettlementExposureSnapshot::query()
            ->where('organization_id', $scope->organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('query_hash', $query->queryHash->value)
            ->where('source_hash', $sourceHash->value)
            ->first();
        if ($existing instanceof ContractSettlementExposureSnapshot) {
            return new ReportSnapshotRef(
                kind: 'contract_settlement_exposure',
                id: (string) $existing->id,
                scope: $scope,
                definitionHash: $query->definition->definitionHash,
                formulaVersion: ContractSettlementCalculator::FORMULA_VERSION,
                sourceHash: $sourceHash,
                generatedAt: $existing->generated_at->toDateTimeImmutable(),
                staleAt: $existing->stale_at?->toDateTimeImmutable(),
                watermarks: [
                    'query_hash' => $query->queryHash->value,
                    'as_of' => $query->asOf->format(DATE_ATOM),
                    'aging_policy_version' => SettlementAgingPolicy::VERSION,
                    'source_fact_id' => (int) $existing->source_watermark_id,
                ],
                classification: ReportSnapshotClassification::OPERATIONAL,
                seal: null,
            );
        }

        $persistedSnapshot = DB::transaction(function () use (
            $scope,
            $scopeHash,
            $query,
            $snapshotId,
            $sourceHash,
            $generatedAt,
            $staleAt,
            $sourceWatermarkId,
            $rows,
            $totals,
            $selectedInputs,
        ): ContractSettlementExposureSnapshot {
            $timestamp = now();
            ContractSettlementSourceFact::query()->insertOrIgnore(array_map(
                static fn (ContractSettlementInput $input): array => [
                    'organization_id' => $scope->organizationId,
                    'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
                    'query_hash' => $query->queryHash->value,
                    'source_hash' => $sourceHash->value,
                    'as_of' => $query->asOf,
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
            $persisted = ContractSettlementSourceFact::query()
                ->where('organization_id', $scope->organizationId)
                ->where('scope_hash', $scopeHash)
                ->where('query_hash', $query->queryHash->value)
                ->orderBy('contract_id')
                ->orderBy('allocation_id')
                ->get();
            if ($persisted->count() !== count($selectedInputs)
                || $persisted->contains(static fn (ContractSettlementSourceFact $fact): bool => ! hash_equals((string) $fact->source_hash, $sourceHash->value))) {
                throw new DomainException('contract_settlement_source_fact_race_conflict');
            }
            $identity = [
                'organization_id' => $scope->organizationId,
                'scope_hash' => $scopeHash,
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
            ];
            $inserted = ContractSettlementExposureSnapshot::query()->insertOrIgnore([[
                'id' => $snapshotId,
                ...$identity,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => ContractSettlementCalculator::FORMULA_VERSION,
                'aging_policy_version' => SettlementAgingPolicy::VERSION,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
                'source_watermark_id' => $sourceWatermarkId,
                'row_count' => count($rows),
                'totals' => $totals,
                'coverage_numerator' => count($rows),
                'coverage_denominator' => count($rows),
                'quality_status' => 'complete',
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $snapshot = ContractSettlementExposureSnapshot::query()->where($identity)->first();
            if (! $snapshot instanceof ContractSettlementExposureSnapshot
                || ! hash_equals((string) $snapshot->source_hash, $sourceHash->value)) {
                throw new DomainException('contract_settlement_snapshot_race_conflict');
            }
            if ($inserted === 1) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    $snapshot->rows()->createMany(array_map(
                        static fn (array $row): array => [
                            ...$row,
                            'organization_id' => $scope->organizationId,
                        ],
                        $chunk,
                    ));
                }
            }

            return $snapshot;
        });

        return new ReportSnapshotRef(
            kind: 'contract_settlement_exposure',
            id: (string) $persistedSnapshot->id,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: ContractSettlementCalculator::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $persistedSnapshot->generated_at->toDateTimeImmutable(),
            staleAt: $persistedSnapshot->stale_at?->toDateTimeImmutable(),
            watermarks: [
                'query_hash' => $query->queryHash->value,
                'as_of' => $query->asOf->format(DATE_ATOM),
                'aging_policy_version' => SettlementAgingPolicy::VERSION,
                'source_fact_id' => (int) $persistedSnapshot->source_watermark_id,
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

    /**
     * @return list<ContractSettlementInput>
     */
    private function persistedInputs(ReportScope $scope, ReportQuery $query): array
    {
        $scopeHash = hash('sha256', CanonicalJson::encode($scope->canonicalIdentity()));
        $facts = ContractSettlementSourceFact::query()
            ->where('organization_id', $scope->organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('query_hash', $query->queryHash->value)
            ->where('as_of', $query->asOf)
            ->orderBy('contract_id')
            ->orderBy('allocation_id')
            ->get();

        return $facts->map(static function (ContractSettlementSourceFact $fact): ContractSettlementInput {
            $currency = (string) $fact->currency;
            if (CurrencyCode::tryFrom($currency) === null) {
                throw new DomainException('contract_settlement_currency_invalid');
            }

            return new ContractSettlementInput(
                contractId: (int) $fact->contract_id,
                allocationId: (int) $fact->allocation_id,
                projectId: $fact->project_id === null ? null : (int) $fact->project_id,
                partyId: $fact->party_id === null ? null : (int) $fact->party_id,
                direction: (string) $fact->direction,
                currency: $currency,
                effectiveMinor: (int) $fact->effective_minor,
                acceptedMinor: (int) $fact->accepted_minor,
                cashMinor: (int) $fact->completed_cash_minor,
                dueAt: $fact->due_at?->toDateTimeImmutable(),
                asOf: new DateTimeImmutable((string) $fact->as_of),
                sourceRefs: (array) $fact->source_refs,
            );
        })->all();
    }
}
