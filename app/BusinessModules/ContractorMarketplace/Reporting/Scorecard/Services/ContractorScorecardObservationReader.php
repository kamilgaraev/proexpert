<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ContractorScorecardObservationReader
{
    public function load(ContractorScorecardSourceTuple $tuple): ContractorObjectiveObservationIndex
    {
        $organizationId = $tuple->baselineScheduleVariance->scope->organizationId;
        $profileByOrganization = DB::table('marketplace_contractor_profiles')
            ->whereIn('id', DB::table('marketplace_hiring_offer_reviews')
                ->where('reviewer_organization_id', $organizationId)
                ->pluck('contractor_profile_id')
                ->all())
            ->pluck('id', 'organization_id')
            ->mapWithKeys(static fn (mixed $id, mixed $organization): array => [(int) $organization => (int) $id])
            ->all();
        $profileByContractor = DB::table('contractors')
            ->where('organization_id', $organizationId)
            ->whereNotNull('source_organization_id')
            ->get(['id', 'source_organization_id'])
            ->mapWithKeys(static fn (object $contractor): array => [
                (int) $contractor->id => $profileByOrganization[(int) $contractor->source_organization_id] ?? null,
            ])
            ->filter()
            ->all();
        $profileBySupplier = DB::table('suppliers')
            ->where('organization_id', $organizationId)
            ->get(['id', 'additional_info'])
            ->mapWithKeys(static function (object $supplier) use ($profileByOrganization): array {
                $metadata = is_string($supplier->additional_info)
                    ? json_decode($supplier->additional_info, true)
                    : $supplier->additional_info;
                if (! is_array($metadata)) {
                    return [(int) $supplier->id => null];
                }
                $profileId = isset($metadata['contractor_profile_id'])
                    ? (int) $metadata['contractor_profile_id']
                    : ($profileByOrganization[(int) ($metadata['contractor_organization_id'] ?? 0)] ?? null);

                return [(int) $supplier->id => $profileId];
            })
            ->filter()
            ->all();

        return new ContractorObjectiveObservationIndex([
            'baseline_schedule_variance' => $this->baselineRows(
                $organizationId,
                $tuple->baselineScheduleVariance->id,
            ),
            'supply_reliability' => $this->contractorRows(
                DB::table('supply_reliability_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->supplyReliability->id)
                    ->get(),
                $profileBySupplier,
                'supplier_id',
            ),
            'quality_defect_flow' => $this->contractorRows(
                DB::table('quality_defect_flow_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->qualityDefectFlow->id)
                    ->get(),
                $profileByContractor,
                'contractor_id',
            ),
            'safety_incident_actions' => $this->contractorRows(
                DB::table('safety_incident_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->safetyIncidentActions->id)
                    ->get(),
                $profileByContractor,
                'contractor_id',
            ),
        ]);
    }

    private function baselineRows(int $organizationId, string $snapshotId): array
    {
        $indexed = [];
        foreach (
            DB::table('baseline_schedule_variance_rows')
                ->where('organization_id', $organizationId)
                ->where('snapshot_id', $snapshotId)
                ->get() as $row
        ) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : $row->payload;
            $projectId = is_array($payload) ? (int) ($payload['project_id'] ?? 0) : 0;
            if ($projectId > 0) {
                $indexed[$projectId][] = [
                    ...$payload,
                    ...(array) $row,
                    'project_id' => $projectId,
                ];
            }
        }

        return $indexed;
    }

    private function contractorRows(
        Collection $rows,
        array $profileByOwnerId,
        string $ownerColumn,
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row->{$ownerColumn} ?? 0);
            $profileId = $profileByOwnerId[$ownerId] ?? null;
            $projectId = (int) ($row->project_id ?? 0);
            if ($profileId !== null && $projectId > 0) {
                $indexed[$profileId][$projectId][] = (array) $row;
            }
        }

        return $indexed;
    }
}
