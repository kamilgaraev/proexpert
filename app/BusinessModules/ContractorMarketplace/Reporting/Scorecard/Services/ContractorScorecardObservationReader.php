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
        $contractors = DB::table('contractors')
            ->where('organization_id', $organizationId)
            ->whereNotNull('source_organization_id')
            ->get(['id', 'source_organization_id']);
        $suppliers = DB::table('suppliers')
            ->where('organization_id', $organizationId)
            ->get(['id', 'additional_info']);
        $supplierMetadata = $suppliers->mapWithKeys(static function (object $supplier): array {
            $metadata = is_string($supplier->additional_info)
                ? json_decode($supplier->additional_info, true)
                : $supplier->additional_info;

            return [(int) $supplier->id => is_array($metadata) ? $metadata : []];
        });
        $profileIds = $supplierMetadata
            ->pluck('contractor_profile_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id);
        $profileOrganizationIds = $contractors
            ->pluck('source_organization_id')
            ->merge($supplierMetadata->pluck('contractor_organization_id'))
            ->filter(static fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $profiles = DB::table('marketplace_contractor_profiles')
            ->where(static function ($query) use ($profileIds, $profileOrganizationIds): void {
                $query->whereIn('id', $profileIds->all())
                    ->orWhereIn('organization_id', $profileOrganizationIds->all());
            })
            ->get(['id', 'organization_id']);
        $profileByOrganization = DB::table('marketplace_contractor_profiles')
            ->whereIn('id', $profiles->pluck('id')->all())
            ->pluck('id', 'organization_id')
            ->mapWithKeys(static fn (mixed $id, mixed $organization): array => [(int) $organization => (int) $id])
            ->all();
        $profileById = $profiles
            ->pluck('id', 'id')
            ->mapWithKeys(static fn (mixed $id): array => [(int) $id => (int) $id])
            ->all();
        $profileByContractor = $contractors
            ->mapWithKeys(static fn (object $contractor): array => [
                (int) $contractor->id => $profileByOrganization[(int) $contractor->source_organization_id] ?? null,
            ])
            ->filter()
            ->all();
        $profileBySupplier = $supplierMetadata
            ->mapWithKeys(static function (array $metadata, int $supplierId) use (
                $profileById,
                $profileByOrganization,
            ): array {
                $profileId = isset($metadata['contractor_profile_id'])
                    ? ($profileById[(int) $metadata['contractor_profile_id']] ?? null)
                    : ($profileByOrganization[(int) ($metadata['contractor_organization_id'] ?? 0)] ?? null);

                return [$supplierId => $profileId];
            })
            ->filter()
            ->all();

        return new ContractorObjectiveObservationIndex([
            'baseline_schedule_variance' => $this->baselineRows(
                $organizationId,
                $tuple->baselineScheduleVariance,
                $profileByContractor,
            ),
            'supply_reliability' => $this->contractorRows(
                DB::table('supply_reliability_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->supplyReliability->id)
                    ->get(),
                $profileBySupplier,
                'supplier_id',
                $tuple->supplyReliability,
            ),
            'quality_defect_flow' => $this->contractorRows(
                DB::table('quality_defect_flow_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->qualityDefectFlow->id)
                    ->get(),
                $profileByContractor,
                'contractor_id',
                $tuple->qualityDefectFlow,
            ),
            'safety_incident_actions' => $this->contractorRows(
                DB::table('safety_incident_rows')
                    ->where('organization_id', $organizationId)
                    ->where('snapshot_id', $tuple->safetyIncidentActions->id)
                    ->get(),
                $profileByContractor,
                'contractor_id',
                $tuple->safetyIncidentActions,
            ),
        ]);
    }

    private function baselineRows(
        int $organizationId,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot,
        array $profileByContractor,
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
                    ...$this->periodIdentity((array) $row + $payload, $snapshot),
                ];
            }
        }

        return $indexed;
    }

    private function contractorRows(
        Collection $rows,
        array $profileByOwnerId,
        string $ownerColumn,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot,
    ): array {
        $indexed = [];
        foreach ($rows as $row) {
            $ownerId = (int) ($row->{$ownerColumn} ?? 0);
            $profileId = $profileByOwnerId[$ownerId] ?? null;
            $projectId = (int) ($row->project_id ?? 0);
            if ($profileId !== null && $projectId > 0) {
                $indexed[$profileId][$projectId][] = [
                    ...(array) $row,
                    ...$this->periodIdentity((array) $row, $snapshot),
                ];
            }
        }

        return $indexed;
    }

    private function periodIdentity(
        array $row,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef $snapshot,
    ): array {
        $explicitCohort = $snapshot->watermarks['cohort_key'] ?? null;
        if (is_string($explicitCohort) && $explicitCohort !== '') {
            return ['_cohort_key' => $explicitCohort];
        }
        foreach ([
            'occurred_at',
            'delivery_date',
            'delivered_at',
            'accepted_at',
            'closed_at',
            'opened_at',
            'work_date',
            'period_end',
            'due_date',
        ] as $field) {
            if (is_string($row[$field] ?? null) && $row[$field] !== '') {
                return ['_observed_at' => $row[$field]];
            }
        }

        $asOf = $snapshot->watermarks['as_of'] ?? null;
        if (! is_string($asOf) || $asOf === '') {
            throw new \InvalidArgumentException('contractor_objective_observation_period_missing');
        }

        return ['_observed_at' => $asOf];
    }
}
