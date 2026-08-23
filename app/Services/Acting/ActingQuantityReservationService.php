<?php

declare(strict_types=1);

namespace App\Services\Acting;

use App\Exceptions\BusinessLogicException;
use App\Models\CompletedWork;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\AcceptedProductionQuantity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ActingQuantityReservationService
{
    /** @return array<int, int> */
    public function availableQuantities(Collection $lockedWorks, ?int $excludedActId = null): array
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('acting_quantity_reservation_requires_transaction');
        }

        $workIds = $lockedWorks
            ->map(static fn (CompletedWork $work): int => (int) $work->id)
            ->sort()
            ->values()
            ->all();
        if ($workIds === []) {
            return [];
        }

        $lineReservations = DB::table('performance_act_lines as acting_lines')
            ->join(
                'contract_performance_acts as acting_acts',
                'acting_acts.id',
                '=',
                'acting_lines.performance_act_id',
            )
            ->whereIn('acting_lines.completed_work_id', $workIds)
            ->where('acting_lines.line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->whereNotIn('acting_acts.status', ActingQuantityStatus::releasedStatuses())
            ->when(
                $excludedActId !== null,
                static fn ($query) => $query->where('acting_acts.id', '!=', $excludedActId),
            )
            ->get(['acting_lines.completed_work_id', 'acting_lines.quantity']);

        $legacyReservations = DB::table('performance_act_completed_works as acting_links')
            ->join(
                'contract_performance_acts as acting_acts',
                'acting_acts.id',
                '=',
                'acting_links.performance_act_id',
            )
            ->whereIn('acting_links.completed_work_id', $workIds)
            ->whereNotIn('acting_acts.status', ActingQuantityStatus::releasedStatuses())
            ->when(
                $excludedActId !== null,
                static fn ($query) => $query->where('acting_acts.id', '!=', $excludedActId),
            )
            ->whereNotExists(static function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('performance_act_lines as canonical_lines')
                    ->whereColumn('canonical_lines.performance_act_id', 'acting_links.performance_act_id')
                    ->whereColumn('canonical_lines.completed_work_id', 'acting_links.completed_work_id')
                    ->where('canonical_lines.line_type', PerformanceActLine::TYPE_COMPLETED_WORK);
            })
            ->get(['acting_links.completed_work_id', 'acting_links.included_quantity']);

        $reserved = [];
        foreach ($lineReservations as $reservation) {
            $workId = (int) $reservation->completed_work_id;
            $reserved[$workId] = ($reserved[$workId] ?? 0) + AcceptedProductionQuantity::scaled(
                (string) $reservation->quantity,
                'acting_quantity_reservation_invalid',
            );
        }
        foreach ($legacyReservations as $reservation) {
            $workId = (int) $reservation->completed_work_id;
            $reserved[$workId] = ($reserved[$workId] ?? 0) + AcceptedProductionQuantity::scaled(
                (string) $reservation->included_quantity,
                'acting_quantity_reservation_invalid',
            );
        }

        $available = [];
        foreach ($lockedWorks as $work) {
            $workId = (int) $work->id;
            $effective = AcceptedProductionQuantity::scaled(
                (string) ($work->completed_quantity ?? $work->quantity ?? '0'),
                'acting_quantity_source_invalid',
            );
            $alreadyReserved = $reserved[$workId] ?? 0;
            $available[$workId] = max(0, $effective - $alreadyReserved);
        }

        return $available;
    }

    /** @return array<int, int> */
    public function approvedQuantities(
        Collection $lockedWorks,
        int $organizationId,
        int $excludedActId,
    ): array {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('acting_quantity_reservation_requires_transaction');
        }

        $workIds = $lockedWorks
            ->map(static fn (CompletedWork $work): int => (int) $work->id)
            ->sort()
            ->values()
            ->all();
        if ($workIds === []) {
            return [];
        }

        $approvedScope = static function ($query): void {
            $query
                ->where('acting_acts.is_approved', true)
                ->orWhereIn('acting_acts.status', ActingQuantityStatus::approvedStatuses());
        };
        $lineQuantities = DB::table('performance_act_lines as acting_lines')
            ->join(
                'contract_performance_acts as acting_acts',
                'acting_acts.id',
                '=',
                'acting_lines.performance_act_id',
            )
            ->join('contracts as acting_contracts', 'acting_contracts.id', '=', 'acting_acts.contract_id')
            ->where('acting_contracts.organization_id', $organizationId)
            ->whereIn('acting_lines.completed_work_id', $workIds)
            ->where('acting_lines.line_type', PerformanceActLine::TYPE_COMPLETED_WORK)
            ->where('acting_acts.id', '!=', $excludedActId)
            ->where($approvedScope)
            ->get(['acting_lines.completed_work_id', 'acting_lines.quantity']);
        $legacyQuantities = DB::table('performance_act_completed_works as acting_links')
            ->join(
                'contract_performance_acts as acting_acts',
                'acting_acts.id',
                '=',
                'acting_links.performance_act_id',
            )
            ->join('contracts as acting_contracts', 'acting_contracts.id', '=', 'acting_acts.contract_id')
            ->where('acting_contracts.organization_id', $organizationId)
            ->whereIn('acting_links.completed_work_id', $workIds)
            ->where('acting_acts.id', '!=', $excludedActId)
            ->where($approvedScope)
            ->whereNotExists(static function ($query): void {
                $query
                    ->selectRaw('1')
                    ->from('performance_act_lines as canonical_lines')
                    ->whereColumn('canonical_lines.performance_act_id', 'acting_links.performance_act_id')
                    ->whereColumn('canonical_lines.completed_work_id', 'acting_links.completed_work_id')
                    ->where('canonical_lines.line_type', PerformanceActLine::TYPE_COMPLETED_WORK);
            })
            ->get(['acting_links.completed_work_id', 'acting_links.included_quantity']);

        $approved = [];
        foreach ($lineQuantities as $quantity) {
            $workId = (int) $quantity->completed_work_id;
            $approved[$workId] = ($approved[$workId] ?? 0) + AcceptedProductionQuantity::scaled(
                (string) $quantity->quantity,
                'acting_approved_quantity_invalid',
            );
        }
        foreach ($legacyQuantities as $quantity) {
            $workId = (int) $quantity->completed_work_id;
            $approved[$workId] = ($approved[$workId] ?? 0) + AcceptedProductionQuantity::scaled(
                (string) $quantity->included_quantity,
                'acting_approved_quantity_invalid',
            );
        }

        return $approved;
    }

    /** @param array<int, int|string|float> $requestedQuantities */
    public function assertAvailable(array $requestedQuantities, array $availableQuantities): void
    {
        $requestedScaled = [];
        foreach ($requestedQuantities as $workId => $quantity) {
            $requestedScaled[(int) $workId] = AcceptedProductionQuantity::scaled(
                (string) $quantity,
                'acting_quantity_requested_invalid',
            );
        }

        $this->assertScaledAvailable($requestedScaled, $availableQuantities);
    }

    /** @param array<int, int> $requestedQuantities */
    public function assertScaledAvailable(array $requestedQuantities, array $availableQuantities): void
    {
        foreach ($requestedQuantities as $workId => $requested) {
            if ($requested <= 0 || $requested > ($availableQuantities[(int) $workId] ?? -1)) {
                throw new BusinessLogicException(
                    trans_message('act_reports.invalid_acting_quantity'),
                    422,
                );
            }
        }
    }
}
