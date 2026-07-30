<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorMembershipEvidence;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ContractorScorecardObservationReader
{
    public function __construct(
        private ContractorObjectiveObservationPeriodResolver $periods,
        private ContractorMembershipEvidenceResolver $memberships,
    ) {}

    public function load(ContractorScorecardSourceTuple $tuple): ContractorObjectiveObservationIndex
    {
        $organizationId = $tuple->baselineScheduleVariance->scope->organizationId;
        $asOf = $tuple->marketplaceReviews->watermarks['as_of'] ?? null;
        $expectedMembershipHash = $tuple->marketplaceReviews->watermarks['membership_evidence_hash'] ?? null;
        if (! is_string($asOf) || ! is_string($expectedMembershipHash)) {
            throw new InvalidArgumentException('contractor_membership_evidence_unpinned');
        }
        $membershipEvidence = $this->memberships->resolve($organizationId, CarbonImmutable::parse($asOf));
        if (! hash_equals($expectedMembershipHash, $membershipEvidence->sourceHash)) {
            throw new InvalidArgumentException('contractor_membership_evidence_changed');
        }
        $baselineRows = DB::table('baseline_schedule_variance_rows')
            ->where('organization_id', $organizationId)
            ->where('snapshot_id', $tuple->baselineScheduleVariance->id)
            ->get();
        $supplyRows = DB::table('supply_reliability_rows')
            ->where('organization_id', $organizationId)
            ->where('snapshot_id', $tuple->supplyReliability->id)
            ->get();
        $qualityRows = DB::table('quality_defect_flow_rows')
            ->where('organization_id', $organizationId)
            ->where('snapshot_id', $tuple->qualityDefectFlow->id)
            ->get();
        $safetyRows = DB::table('safety_incident_rows')
            ->where('organization_id', $organizationId)
            ->where('snapshot_id', $tuple->safetyIncidentActions->id)
            ->get();
        $periodRows = [
            'baseline_schedule_variance' => $baselineRows,
            'supply_reliability' => $supplyRows,
            'quality_defect_flow' => $qualityRows,
            'safety_incident_actions' => $safetyRows,
        ];
        $timestamps = [];
        foreach ($periodRows as $sourceReportCode => $rows) {
            foreach ($rows as $row) {
                $payload = (array) $row;
                if ($sourceReportCode === 'baseline_schedule_variance') {
                    $decoded = is_string($row->payload ?? null) ? json_decode($row->payload, true) : ($row->payload ?? []);
                    $payload += is_array($decoded) ? $decoded : [];
                }
                $timestamps[] = CarbonImmutable::parse($this->periods->resolve($payload, $sourceReportCode));
            }
        }
        $membershipTimeline = $this->memberships->resolveMany($organizationId, $timestamps);

        return new ContractorObjectiveObservationIndex([
            'baseline_schedule_variance' => $this->baselineRows(
                $baselineRows,
                $membershipTimeline,
                'baseline_schedule_variance',
            ),
            'supply_reliability' => $this->contractorRows(
                $supplyRows,
                $membershipTimeline,
                'supplier_id',
                'supply_reliability',
            ),
            'quality_defect_flow' => $this->contractorRows(
                $qualityRows,
                $membershipTimeline,
                'contractor_id',
                'quality_defect_flow',
            ),
            'safety_incident_actions' => $this->contractorRows(
                $safetyRows,
                $membershipTimeline,
                'contractor_id',
                'safety_incident_actions',
            ),
        ]);
    }

    private function baselineRows(
        Collection $rows,
        array $membershipTimeline,
        string $sourceReportCode,
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
            $projectId = is_array($payload) ? (int) ($payload['project_id'] ?? 0) : 0;
            $contractorId = is_array($payload) ? (int) ($payload['contractor_id'] ?? 0) : 0;
            $period = $this->periodIdentity((array) $row + (array) $payload, $sourceReportCode);
            $membership = $membershipTimeline[$period['_observed_at']] ?? null;
            if (! $membership instanceof ContractorMembershipEvidence) {
                throw new InvalidArgumentException('contractor_membership_evidence_unpinned');
            }
            $profileId = $membership->profileByContractor[$contractorId] ?? null;
            if ($projectId > 0 && $profileId !== null) {
                $indexed[$profileId][$projectId][] = [
                    ...$payload,
                    ...(array) $row,
                    'project_id' => $projectId,
                    '_category_ids' => array_map(
                        'intval',
                        array_keys($membership->categoriesByProfile[$profileId] ?? []),
                    ),
                    '_profile_organization_id' => $membership->profileOrganizationById[$profileId] ?? null,
                    ...$period,
                ];
            }
        }

        return $indexed;
    }

    private function contractorRows(
        Collection $rows,
        array $membershipTimeline,
        string $ownerColumn,
        string $sourceReportCode,
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row->{$ownerColumn} ?? 0);
            $period = $this->periodIdentity((array) $row, $sourceReportCode);
            $membership = $membershipTimeline[$period['_observed_at']] ?? null;
            if (! $membership instanceof ContractorMembershipEvidence) {
                throw new InvalidArgumentException('contractor_membership_evidence_unpinned');
            }
            $profileByOwnerId = $ownerColumn === 'supplier_id'
                ? $membership->profileBySupplier
                : $membership->profileByContractor;
            $profileId = $profileByOwnerId[$ownerId] ?? null;
            $projectId = (int) ($row->project_id ?? 0);
            if ($profileId !== null && $projectId > 0) {
                $indexed[$profileId][$projectId][] = [
                    ...(array) $row,
                    '_category_ids' => array_map(
                        'intval',
                        array_keys($membership->categoriesByProfile[$profileId] ?? []),
                    ),
                    '_profile_organization_id' => $membership->profileOrganizationById[$profileId] ?? null,
                    ...$period,
                ];
            }
        }

        return $indexed;
    }

    private function periodIdentity(
        array $row,
        string $sourceReportCode,
    ): array {
        return ['_observed_at' => $this->periods->resolve($row, $sourceReportCode)];
    }
}
