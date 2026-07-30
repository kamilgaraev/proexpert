<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Payments\Reporting\FinanceSourceAccessPolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeExposureFact;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimLink;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeClaimSnapshot;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeRequestVersion;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeWorkflowEvent;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ContingencyLedgerEntry;
use DateInterval;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ChangeClaimSnapshotMaterializer
{
    public const FORMULA_VERSION = 'change-claim-contingency.v1';

    public function __construct(
        private ChangeClaimContingencyFormula $formula,
        private FinanceSourceAccessPolicy $sourceAccess,
    ) {}

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== 'change_claim_contingency'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }
        $filters = $query->filters->values;
        $this->assertSupportedFilters($filters);
        $versionsQuery = ChangeRequestVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scope->projectIds));
        $this->applyFilters($versionsQuery, $filters, [
            'project_ids' => 'project_id',
            'contract_ids' => 'contract_id',
            'allocation_ids' => 'contract_project_allocation_id',
            'change_request_ids' => 'change_request_id',
            'statuses' => 'status',
            'currencies' => 'currency',
            'initiator_types' => 'initiator_type',
            'initiator_user_ids' => 'initiator_user_id',
            'owner_user_ids' => 'owner_user_id',
            'reasons' => 'reason',
        ]);
        $versionsQuery
            ->when(isset($filters['period_from']), static fn (Builder $builder) => $builder->whereDate('effective_at', '>=', (string) $filters['period_from']))
            ->when(isset($filters['period_to']), static fn (Builder $builder) => $builder->whereDate('effective_at', '<=', (string) $filters['period_to']));
        $versions = $versionsQuery
            ->orderBy('id')
            ->get()
            ->filter(fn (ChangeRequestVersion $version): bool => $this->sourceAccess->allowsAggregate(
                $scope,
                [
                    ['type' => 'change_request', 'id' => (int) $version->change_request_id],
                    ['type' => 'contract', 'id' => $version->contract_id],
                    ['type' => 'contract_allocation', 'id' => $version->contract_project_allocation_id],
                ],
                ['change_request', 'contract', 'contract_allocation'],
            ))
            ->values();
        $ledgerQuery = ContingencyLedgerEntry::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn (Builder $builder) => $builder->whereIn('project_id', $scope->projectIds));
        $this->applyFilters($ledgerQuery, $filters, [
            'project_ids' => 'project_id',
            'allocation_ids' => 'contract_project_allocation_id',
            'currencies' => 'currency',
            'source_types' => 'source_type',
        ]);
        if (isset($filters['contract_ids'])) {
            $ledgerQuery->whereIn('contract_project_allocation_id', $versions->pluck('contract_project_allocation_id')->filter());
        }
        $ledgerQuery
            ->when(isset($filters['period_to']), static fn (Builder $builder) => $builder->whereDate('effective_on', '<=', (string) $filters['period_to']));
        $ledger = $ledgerQuery
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (ContingencyLedgerEntry $entry): bool => $this->sourceAccess->allowsAggregate(
                $scope,
                [
                    ['type' => 'contract_allocation', 'id' => (int) $entry->contract_project_allocation_id],
                    ['type' => (string) $entry->source_type, 'id' => (string) $entry->source_id],
                ],
                ['change_request', 'contract_allocation'],
            ))
            ->values();
        if ($versions->isEmpty() && $ledger->isEmpty()) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $links = ChangeClaimLink::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('change_request_version_id', $versions->pluck('id'))
            ->when(isset($filters['claim_ids']), static fn (Builder $builder) => $builder
                ->whereIn('change_claim_id', is_array($filters['claim_ids']) ? $filters['claim_ids'] : [$filters['claim_ids']]))
            ->get()
            ->groupBy('change_request_version_id');
        $events = ChangeWorkflowEvent::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('change_request_id', $versions->pluck('change_request_id'))
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('change_request_id');

        $groups = [];
        $warnings = [];
        foreach ($versions as $version) {
            if ($version->contract_project_allocation_id === null || $version->currency === null) {
                $warnings[] = [
                    'code' => 'change_version_monetary_source_incomplete',
                    'change_request_id' => (int) $version->change_request_id,
                    'version' => (int) $version->version,
                ];

                continue;
            }
            $key = $version->project_id.':'.$version->contract_project_allocation_id.':'.$version->currency;
            $groups[$key] ??= ['facts' => [], 'movements' => [], 'versions' => [], 'claims' => [], 'ledger' => []];
            $claimRows = $links->get($version->id, collect());
            if (isset($filters['claim_ids']) && $claimRows->isEmpty()) {
                continue;
            }
            $groups[$key]['facts'][] = new ChangeExposureFact(
                changeRequestId: (int) $version->change_request_id,
                changeVersion: (int) $version->version,
                projectId: (int) $version->project_id,
                allocationId: (int) $version->contract_project_allocation_id,
                currency: (string) $version->currency,
                proposedMinor: (int) $version->proposed_cost_minor,
                approvedMinor: $version->approved_cost_minor === null ? null : (int) $version->approved_cost_minor,
                linkedClaims: $claimRows->map(static fn (ChangeClaimLink $link): array => [
                    'id' => (int) $link->change_claim_id,
                    'version' => (int) $link->claim_version,
                    'amount_minor' => (int) $link->claim_amount_minor,
                ])->all(),
            );
            $groups[$key]['versions'][] = $version;
            foreach ($claimRows as $claimRow) {
                $groups[$key]['claims'][] = $claimRow;
            }
        }
        foreach ($ledger as $entry) {
            $key = $entry->project_id.':'.$entry->contract_project_allocation_id.':'.$entry->currency;
            $groups[$key] ??= ['facts' => [], 'movements' => [], 'versions' => [], 'claims' => [], 'ledger' => []];
            $groups[$key]['ledger'][] = $entry;
        }

        $rows = [];
        $completeVersionEvidence = 0;
        $requiredApprovalEvidence = 0;
        $completeApprovalEvidence = 0;
        $completeLedgerEvidence = 0;
        foreach ($groups as $key => $group) {
            [$projectId, $allocationId] = array_map('intval', explode(':', $key, 3));
            $claimsByVersion = collect($group['claims'])->groupBy('change_request_version_id');
            $facts = collect($group['facts'])->keyBy(
                static fn (ChangeExposureFact $fact): string => $fact->changeRequestId.':'.$fact->changeVersion,
            );
            $sortedVersions = collect($group['versions'])->sortBy([
                ['effective_at', 'asc'],
                ['version', 'asc'],
            ])->values();
            if ($sortedVersions->isEmpty() && $group['ledger'] !== []) {
                $warnings[] = ['code' => 'contingency_change_version_missing', 'allocation_id' => $allocationId];

                continue;
            }
            $latestEffectiveAt = $sortedVersions->last()->effective_at;
            $coveredLedger = collect($group['ledger'])
                ->filter(static fn ($entry): bool => $entry->effective_at <= $latestEffectiveAt);
            $completeLedgerEvidence += $coveredLedger->count();
            if ($coveredLedger->count() !== count($group['ledger'])) {
                $warnings[] = ['code' => 'contingency_ledger_version_missing', 'allocation_id' => $allocationId];
            }
            foreach ($sortedVersions as $version) {
                $fact = $facts->get($version->change_request_id.':'.$version->version);
                if (! $fact instanceof ChangeExposureFact) {
                    $warnings[] = [
                        'code' => 'change_version_fact_missing',
                        'change_request_id' => (int) $version->change_request_id,
                        'version' => (int) $version->version,
                    ];

                    continue;
                }
                $completeVersionEvidence++;
                $metric = $this->formula->summarize([$fact], []);
                $versionClaims = $claimsByVersion->get($version->id, collect());
                $event = $events->get($version->change_request_id, collect())
                    ->firstWhere('version', $version->version);
                $versionLedger = collect($group['ledger'])
                    ->filter(static fn ($entry): bool => (string) $entry->source_type === 'change_request'
                        && (string) $entry->source_id === (string) $version->change_request_id
                        && (int) $entry->source_version === (int) $version->version)
                    ->values();
                $ledgerEvidence = collect($group['ledger'])
                    ->filter(static fn ($entry): bool => $entry->effective_at <= $version->effective_at)
                    ->values();
                $ledgerMetric = $this->ledgerMetric($ledgerEvidence->all(), $filters, (string) $version->currency);
                $requiresConsumption = $event?->event_type === 'approve';
                $hasConsumption = $versionLedger->contains(
                    static fn ($entry): bool => (string) $entry->movement_type === 'consumption',
                );
                if ($requiresConsumption) {
                    $requiredApprovalEvidence++;
                    if ($hasConsumption) {
                        $completeApprovalEvidence++;
                    }
                }
                if ($requiresConsumption && ! $hasConsumption) {
                    $warnings[] = [
                        'code' => 'approved_change_contingency_consumption_missing',
                        'change_request_id' => (int) $version->change_request_id,
                        'version' => (int) $version->version,
                    ];
                }
                $rows[] = [
                    'row_key' => hash('sha256', $key.':change:'.$version->change_request_id.':'.$version->version),
                    'organization_id' => $scope->organizationId,
                    'project_id' => $projectId,
                    'contract_id' => $version->contract_id,
                    'contract_project_allocation_id' => $allocationId,
                    'change_request_id' => $version->change_request_id,
                    'change_version' => $version->version,
                    'status' => (string) $version->status,
                    'occurred_on' => ($event?->occurred_at ?? $version->effective_at)->format('Y-m-d'),
                    'currency' => $metric->currency,
                    'proposed_exposure_minor' => $metric->proposedExposureMinor,
                    'approved_exposure_minor' => $metric->approvedExposureMinor,
                    'linked_claim_minor' => $metric->linkedClaimMinor,
                    'opening_contingency_minor' => $ledgerMetric?->openingContingencyMinor ?? 0,
                    'allocated_contingency_minor' => $ledgerMetric?->allocatedContingencyMinor ?? 0,
                    'consumed_contingency_minor' => $ledgerMetric?->consumedContingencyMinor ?? 0,
                    'released_contingency_minor' => $ledgerMetric?->releasedContingencyMinor ?? 0,
                    'closing_contingency_minor' => $ledgerMetric?->closingContingencyMinor ?? 0,
                    'quality_status' => 'complete',
                    'source_refs' => [
                        [
                            'type' => 'change_request',
                            'id' => (string) $version->change_request_id,
                            'version' => (int) $version->version,
                            'hash' => (string) $version->source_hash,
                        ],
                        ...$versionClaims->map(static fn (ChangeClaimLink $claim): array => [
                            'type' => 'change_claim',
                            'id' => (string) $claim->change_claim_id,
                            'version' => (int) $claim->claim_version,
                            'hash' => (string) $claim->source_hash,
                        ])->values()->all(),
                        ...array_map(static fn ($entry): array => [
                            'type' => (string) $entry->source_type,
                            'id' => (string) $entry->source_id,
                            'version' => (int) $entry->source_version,
                            'hash' => (string) $entry->entry_hash,
                        ], $ledgerEvidence->all()),
                    ],
                ];
            }
        }
        if ($rows === []) {
            throw new DomainException('change_claim_monetary_evidence_unavailable');
        }
        $snapshotId = (string) Str::ulid();
        $generatedAt = $query->asOf;
        $staleAt = $generatedAt->add(new DateInterval('PT10M'));
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'query_hash' => $query->queryHash->value,
            'version_watermark_id' => (int) $versions->max('id'),
            'ledger_watermark_id' => (int) $ledger->max('id'),
            'rows' => $rows,
            'warnings' => $warnings,
        ])));
        $totals = $this->totals($rows);
        $coverageDenominator = $versions->count() + $requiredApprovalEvidence + $ledger->count();
        $coverageNumerator = $completeVersionEvidence + $completeApprovalEvidence + $completeLedgerEvidence;
        $quality = $warnings === [] && $coverageNumerator === $coverageDenominator
            ? 'complete'
            : 'partial';
        if ($quality === 'partial') {
            $rows = array_map(static fn (array $row): array => [
                ...$row,
                'quality_status' => 'partial',
            ], $rows);
        }

        $persistedSnapshotId = DB::transaction(function () use (
            $scope,
            $query,
            $snapshotId,
            $sourceHash,
            $versions,
            $ledger,
            $generatedAt,
            $staleAt,
            $rows,
            $totals,
            $coverageNumerator,
            $coverageDenominator,
            $quality,
            $warnings,
        ): string {
            $identity = [
                'organization_id' => $scope->organizationId,
                'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
            ];
            $inserted = ChangeClaimSnapshot::query()->insertOrIgnore([[
                'id' => $snapshotId,
                ...$identity,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => self::FORMULA_VERSION,
                'version_watermark_id' => (int) $versions->max('id'),
                'ledger_watermark_id' => (int) $ledger->max('id'),
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
                'row_count' => count($rows),
                'totals' => $totals,
                'coverage_numerator' => $coverageNumerator,
                'coverage_denominator' => $coverageDenominator,
                'quality_status' => $quality,
                'warnings' => $warnings,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $snapshot = ChangeClaimSnapshot::query()->where($identity)->first();
            if (! $snapshot instanceof ChangeClaimSnapshot
                || ! hash_equals((string) $snapshot->source_hash, $sourceHash->value)) {
                throw new DomainException('change_claim_snapshot_race_conflict');
            }
            if ($inserted === 1) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    $snapshot->rows()->createMany($chunk);
                }
            }

            return (string) $snapshot->id;
        });

        return new ReportSnapshotRef(
            kind: 'change_claim_contingency',
            id: $persistedSnapshotId,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: self::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: [
                'query_hash' => $query->queryHash->value,
                'as_of' => $query->asOf->format(DATE_ATOM),
                'change_request_version_id' => (int) $versions->max('id'),
                'contingency_ledger_entry_id' => (int) $ledger->max('id'),
            ],
            classification: ReportSnapshotClassification::OPERATIONAL,
            seal: null,
        );
    }

    private function ledgerMetric(array $entries, array $filters, string $currency): ?\App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ChangeClaimMetric
    {
        if ($entries === []) {
            return null;
        }
        $openingBalance = 0;
        $movements = [];
        foreach ($entries as $entry) {
            if (isset($filters['period_from'])
                && $entry->effective_on->format('Y-m-d') < (string) $filters['period_from']) {
                $openingBalance += (int) $entry->signed_amount_minor;

                continue;
            }
            $movements[] = ContingencyMovement::recorded(
                type: (string) $entry->movement_type,
                amountMinor: abs((int) $entry->signed_amount_minor),
                currency: (string) $entry->currency,
                projectId: (int) $entry->project_id,
                allocationId: (int) $entry->contract_project_allocation_id,
                sourceType: (string) $entry->source_type,
                sourceId: (string) $entry->source_id,
                sourceVersion: (int) $entry->source_version,
                idempotencyKey: (string) $entry->idempotency_key,
            );
        }
        if ($openingBalance < 0) {
            throw new DomainException('contingency_opening_balance_negative');
        }
        if (isset($filters['period_from'])) {
            array_unshift($movements, ContingencyMovement::opening($openingBalance, $currency));
        }

        return $this->formula->summarize([], $movements);
    }

    private function applyFilters(Builder $builder, array $filters, array $columns): void
    {
        foreach ($columns as $filter => $column) {
            if (! array_key_exists($filter, $filters)) {
                continue;
            }
            $values = is_array($filters[$filter]) ? $filters[$filter] : [$filters[$filter]];
            $builder->whereIn($column, $values);
        }
    }

    private function assertSupportedFilters(array $filters): void
    {
        $supported = array_fill_keys([
            'period_from',
            'period_to',
            'project_ids',
            'contract_ids',
            'allocation_ids',
            'change_request_ids',
            'statuses',
            'currencies',
            'initiator_types',
            'initiator_user_ids',
            'owner_user_ids',
            'reasons',
            'source_types',
            'claim_ids',
        ], true);
        foreach (array_keys($filters) as $filter) {
            if (! isset($supported[$filter])) {
                throw new DomainException('report_filter_not_sealed');
            }
        }
    }

    private function totals(array $rows): array
    {
        $totals = [];
        $latestChanges = [];
        $latestAllocations = [];
        foreach ($rows as $row) {
            $currency = $row['currency'];
            $totals[$currency] ??= [
                'proposed_exposure_minor' => 0,
                'approved_exposure_minor' => 0,
                'linked_claim_minor' => 0,
                'closing_contingency_minor' => 0,
            ];
            $totals[$currency]['linked_claim_minor'] += $row['linked_claim_minor'];
            $changeKey = $currency.':'.$row['change_request_id'];
            if (! isset($latestChanges[$changeKey])
                || (int) $latestChanges[$changeKey]['change_version'] < (int) $row['change_version']) {
                $latestChanges[$changeKey] = $row;
            }
            $allocationKey = $currency.':'.$row['project_id'].':'.$row['contract_project_allocation_id'];
            if (! isset($latestAllocations[$allocationKey])
                || strcmp((string) $latestAllocations[$allocationKey]['occurred_on'], (string) $row['occurred_on']) < 0
                || ($latestAllocations[$allocationKey]['occurred_on'] === $row['occurred_on']
                    && (int) $latestAllocations[$allocationKey]['change_version'] < (int) $row['change_version'])) {
                $latestAllocations[$allocationKey] = $row;
            }
        }
        foreach ($latestChanges as $row) {
            $currency = $row['currency'];
            $totals[$currency]['proposed_exposure_minor'] += $row['proposed_exposure_minor'];
            $totals[$currency]['approved_exposure_minor'] += $row['approved_exposure_minor'];
        }
        foreach ($latestAllocations as $row) {
            $totals[$row['currency']]['closing_contingency_minor'] += $row['closing_contingency_minor'];
        }
        ksort($totals);

        return $totals;
    }
}
