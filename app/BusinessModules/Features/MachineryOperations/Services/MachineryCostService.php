<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Services;

use App\BusinessModules\Features\MachineryOperations\Models\MachineryFuelIssue;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryMaintenanceOrder;
use App\BusinessModules\Features\MachineryOperations\Models\MachineryShiftReport;
use Carbon\CarbonImmutable;

final class MachineryCostService
{
    /** @return array<string, mixed> */
    public function calculate(
        int $organizationId,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        ?int $projectId = null,
    ): array {
        $effectiveDateTo = $dateTo->lessThan(CarbonImmutable::today()) ? $dateTo : CarbonImmutable::today();
        $shifts = MachineryShiftReport::forOrganization($organizationId)
            ->with('asset:id,operating_cost_per_hour,ownership_type,metadata')
            ->where('status', 'approved')
            ->whereBetween('report_date', [$dateFrom->toDateString(), $effectiveDateTo->toDateString()])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->orderBy('report_date')
            ->get();
        $fuel = MachineryFuelIssue::forOrganization($organizationId)
            ->whereBetween('issued_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get();
        $maintenance = MachineryMaintenanceOrder::forOrganization($organizationId)
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$dateFrom->startOfDay(), $dateTo->endOfDay()])
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->get();

        $shiftEvidence = $shifts->map(function (MachineryShiftReport $shift): array {
            $snapshot = $shift->hourly_rate_snapshot;
            $rate = (float) ($snapshot ?? $shift->asset?->operating_cost_per_hour ?? 0);

            return [
                'shift_report_id' => (int) $shift->id,
                'project_id' => (int) $shift->project_id,
                'report_date' => $shift->report_date?->toDateString(),
                'approved_at' => $shift->approved_at?->toIso8601String(),
                'actual_hours' => (float) $shift->actual_hours,
                'hourly_rate' => $rate,
                'rate_source' => $snapshot !== null ? 'approval_snapshot' : 'legacy_current_rate',
                'rate_evidence' => $shift->cost_evidence,
                'cost' => round((float) $shift->actual_hours * $rate, 2),
            ];
        });
        $rentalEvidence = $this->rentalEvidence($shifts->all(), $dateFrom, $dateTo, $projectId);

        $operationCost = round((float) $shiftEvidence->sum('cost'), 2);
        $fuelCost = round((float) $fuel->sum(fn (MachineryFuelIssue $item) => (float) $item->cost), 2);
        $maintenanceCost = round((float) $maintenance->sum(fn (MachineryMaintenanceOrder $item) => (float) $item->cost), 2);
        $rentalCost = round((float) collect($rentalEvidence)->sum('cost'), 2);

        return [
            'period' => ['date_from' => $dateFrom->toDateString(), 'date_to' => $dateTo->toDateString()],
            'project_id' => $projectId,
            'source_status' => 'approved_only',
            'totals' => [
                'operation_cost' => $operationCost,
                'fuel_cost' => $fuelCost,
                'maintenance_cost' => $maintenanceCost,
                'rental_cost' => $rentalCost,
                'total_cost' => round($operationCost + $fuelCost + $maintenanceCost + $rentalCost, 2),
            ],
            'evidence' => [
                'shifts' => $shiftEvidence->values()->all(),
                'fuel_issue_ids' => $fuel->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'maintenance_order_ids' => $maintenance->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'rental' => $rentalEvidence,
            ],
        ];
    }

    /** @param array<int, MachineryShiftReport> $shifts @return array<int, array<string, mixed>> */
    private function rentalEvidence(array $shifts, CarbonImmutable $dateFrom, CarbonImmutable $dateTo, ?int $projectId): array
    {
        $assets = collect($shifts)->map(fn (MachineryShiftReport $shift) => $shift->asset)->filter()->unique('id');
        $evidence = [];
        foreach ($assets as $asset) {
            if ($asset->ownership_type !== 'rented') {
                continue;
            }
            $terms = is_array($asset->metadata) ? ($asset->metadata['rental_terms'] ?? null) : null;
            if (! is_array($terms) || ! is_numeric($terms['daily_rate'] ?? null)) {
                continue;
            }
            if ($projectId !== null && isset($terms['project_id']) && (int) $terms['project_id'] !== $projectId) {
                continue;
            }
            $from = isset($terms['valid_from']) ? CarbonImmutable::parse($terms['valid_from'])->startOfDay() : $dateFrom->startOfDay();
            $to = isset($terms['valid_to']) ? CarbonImmutable::parse($terms['valid_to'])->startOfDay() : $dateTo->startOfDay();
            $start = $from->greaterThan($dateFrom) ? $from : $dateFrom;
            $end = $to->lessThan($dateTo) ? $to : $dateTo;
            if ($end->lessThan($start)) {
                continue;
            }
            $days = $start->diffInDays($end) + 1;
            $rate = (float) $terms['daily_rate'];
            $evidence[] = [
                'asset_id' => (int) $asset->id,
                'date_from' => $start->toDateString(),
                'date_to' => $end->toDateString(),
                'days' => $days,
                'daily_rate' => $rate,
                'terms_version' => (string) ($terms['version'] ?? 'metadata-v1'),
                'cost' => round($days * $rate, 2),
            ];
        }

        return $evidence;
    }
}
