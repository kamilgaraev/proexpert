<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

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

        return new ContractorObjectiveObservationIndex([
            'baseline_schedule_variance' => $this->baselineRows(
                $organizationId,
                $tuple->baselineScheduleVariance,
                $membershipEvidence->profileByContractor,
                'baseline_schedule_variance',
            ),
            'supply_reliability' => $this->contractorRows(
                DB::table('supply_reliability_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->supplyReliability->id)
                    ->get(),
                $membershipEvidence->profileBySupplier,
                'supplier_id',
                'supply_reliability',
            ),
            'quality_defect_flow' => $this->contractorRows(
                DB::table('quality_defect_flow_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->qualityDefectFlow->id)
                    ->get(),
                $membershipEvidence->profileByContractor,
                'contractor_id',
                'quality_defect_flow',
            ),
            'safety_incident_actions' => $this->contractorRows(
                DB::table('safety_incident_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->safetyIncidentActions->id)
                    ->get(),
                $membershipEvidence->profileByContractor,
                'contractor_id',
                'safety_incident_actions',
            ),
        ], $membershipEvidence->categoriesByProfile, $membershipEvidence->profileOrganizationById);
    }

    private function baselineRows(
        int $organizationId,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot,
        array $profileByContractor,
        string $sourceReportCode,
    ): array {
        $indexed = [];
        foreach (
            DB::table('baseline_schedule_variance_rows')
                ->where('organization_id', $organizationId)
                ->where('snapshot_id', $snapshot->id)
                ->get() as $row
        ) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
            $projectId = is_array($payload) ? (int) ($payload['project_id'] ?? 0) : 0;
            $contractorId = is_array($payload) ? (int) ($payload['contractor_id'] ?? 0) : 0;
            $profileId = $profileByContractor[$contractorId] ?? null;
            if ($projectId > 0 && $profileId !== null) {
                $indexed[$profileId][$projectId][] = [
                    ...$payload,
                    ...(array) $row,
                    'project_id' => $projectId,
                    ...$this->periodIdentity((array) $row + $payload, $sourceReportCode),
                ];
            }
        }

        return $indexed;
    }

    private function contractorRows(
        Collection $rows,
        array $profileByOwnerId,
        string $ownerColumn,
        string $sourceReportCode,
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row->{$ownerColumn} ?? 0);
            $profileId = $profileByOwnerId[$ownerId] ?? null;
            $projectId = (int) ($row->project_id ?? 0);
            if ($profileId !== null && $projectId > 0) {
                $indexed[$profileId][$projectId][] = [
                    ...(array) $row,
                    ...$this->periodIdentity((array) $row, $sourceReportCode),
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
