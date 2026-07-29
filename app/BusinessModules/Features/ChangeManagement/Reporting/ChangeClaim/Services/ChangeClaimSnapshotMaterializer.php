<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ChangeClaimSnapshotMaterializer
{
    public const FORMULA_VERSION = 'change-claim-contingency.v1';

    public function __construct(private ChangeClaimContingencyFormula $formula)
    {
    }

    public function materialize(ReportScope $scope, ReportQuery $query): ReportSnapshotRef
    {
        if ($query->definition->code !== 'change_claim_contingency'
            || $query->scope->canonicalIdentity() !== $scope->canonicalIdentity()) {
            throw new DomainException('report_projection_scope_invalid');
        }
        $versions = ChangeRequestVersion::query()
            ->where('organization_id', $scope->organizationId)
            ->where('effective_at', '<=', $query->asOf)
            ->when($scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->orderBy('id')
            ->get();
        $ledger = ContingencyLedgerEntry::query()
            ->where('organization_id', $scope->organizationId)
            ->whereDate('effective_on', '<=', $query->asOf->format('Y-m-d'))
            ->when($scope->projectIds !== [], static fn ($builder) => $builder->whereIn('project_id', $scope->projectIds))
            ->orderBy('id')
            ->get();
        if ($versions->isEmpty() && $ledger->isEmpty()) {
            throw new DomainException('report_mandatory_source_unavailable');
        }

        $links = ChangeClaimLink::query()
            ->where('organization_id', $scope->organizationId)
            ->whereIn('change_request_version_id', $versions->pluck('id'))
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
            $groups[$key] ??= ['facts' => [], 'movements' => [], 'versions' => [], 'ledger' => []];
            $claimRows = $links->get($version->id, collect());
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
        }
        foreach ($ledger as $entry) {
            $key = $entry->project_id.':'.$entry->contract_project_allocation_id.':'.$entry->currency;
            $groups[$key] ??= ['facts' => [], 'movements' => [], 'versions' => [], 'ledger' => []];
            $groups[$key]['movements'][] = ContingencyMovement::recorded(
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
            $groups[$key]['ledger'][] = $entry;
        }

        $rows = [];
        foreach ($groups as $key => $group) {
            if ($group['facts'] === [] && $group['movements'] === []) {
                continue;
            }
            $metric = $this->formula->summarize($group['facts'], $group['movements']);
            $latest = collect($group['versions'])->sortByDesc('version')->first();
            $event = $latest === null ? null : $events->get($latest->change_request_id, collect())->last();
            [$projectId, $allocationId] = array_map('intval', explode(':', $key, 3));
            $rows[] = [
                'row_key' => hash('sha256', $key.':'.($latest?->change_request_id ?? 0).':'.($latest?->version ?? 0)),
                'organization_id' => $scope->organizationId,
                'project_id' => $projectId,
                'contract_id' => $latest?->contract_id,
                'contract_project_allocation_id' => $allocationId,
                'change_request_id' => $latest?->change_request_id,
                'change_version' => $latest?->version,
                'status' => (string) ($latest?->status ?? 'ledger_only'),
                'occurred_on' => ($event?->occurred_at ?? $latest?->effective_at ?? $query->asOf)->format('Y-m-d'),
                'currency' => $metric->currency,
                'proposed_exposure_minor' => $metric->proposedExposureMinor,
                'approved_exposure_minor' => $metric->approvedExposureMinor,
                'linked_claim_minor' => $metric->linkedClaimMinor,
                'opening_contingency_minor' => $metric->openingContingencyMinor,
                'allocated_contingency_minor' => $metric->allocatedContingencyMinor,
                'consumed_contingency_minor' => $metric->consumedContingencyMinor,
                'released_contingency_minor' => $metric->releasedContingencyMinor,
                'closing_contingency_minor' => $metric->closingContingencyMinor,
                'quality_status' => 'complete',
                'source_refs' => [
                    ...array_map(static fn ($version): array => [
                        'type' => 'change_request',
                        'id' => (string) $version->change_request_id,
                        'version' => (int) $version->version,
                        'hash' => (string) $version->source_hash,
                    ], $group['versions']),
                    ...array_map(static fn ($entry): array => [
                        'type' => (string) $entry->source_type,
                        'id' => (string) $entry->source_id,
                        'version' => (int) $entry->source_version,
                        'hash' => (string) $entry->entry_hash,
                    ], $group['ledger']),
                ],
            ];
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
        $coverageDenominator = $versions->count() + $ledger->count();
        $coverageNumerator = $coverageDenominator - count($warnings);
        $quality = $warnings === [] ? 'complete' : 'partial';

        DB::transaction(function () use (
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
        ): void {
            $snapshot = ChangeClaimSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => self::FORMULA_VERSION,
                'scope_hash' => hash('sha256', CanonicalJson::encode($scope->canonicalIdentity())),
                'query_hash' => $query->queryHash->value,
                'source_hash' => $sourceHash->value,
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
            ]);
            foreach (array_chunk($rows, 500) as $chunk) {
                $snapshot->rows()->createMany($chunk);
            }
        });

        return new ReportSnapshotRef(
            kind: 'change_claim_contingency',
            id: $snapshotId,
            scope: $scope,
            definitionHash: $query->definition->definitionHash,
            formulaVersion: self::FORMULA_VERSION,
            sourceHash: $sourceHash,
            generatedAt: $generatedAt,
            staleAt: $staleAt,
            watermarks: [
                'change_request_version_id' => (int) $versions->max('id'),
                'contingency_ledger_entry_id' => (int) $ledger->max('id'),
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
                'proposed_exposure_minor' => 0,
                'approved_exposure_minor' => 0,
                'linked_claim_minor' => 0,
                'closing_contingency_minor' => 0,
            ];
            foreach (array_keys($totals[$currency]) as $field) {
                $totals[$currency][$field] += $row[$field];
            }
        }
        ksort($totals);

        return $totals;
    }
}
