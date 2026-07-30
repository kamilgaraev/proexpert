<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Services;

use App\Models\ContractPerformanceAct;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class AcceptedProductionBackfill
{
    public function __construct(private AcceptedProductionBackfillReconciler $reconciler) {}

    public function run(int $organizationId, array $projectIds, ?int $actorId = null): array
    {
        if ($organizationId < 1 || $projectIds === []) {
            throw new InvalidArgumentException('accepted_production_backfill_scope_invalid');
        }

        $eventIds = [];
        $acts = ContractPerformanceAct::query()
            ->whereIn('project_id', $projectIds)
            ->whereHas('contract', static fn ($builder) => $builder->where('organization_id', $organizationId))
            ->where(function ($builder): void {
                $builder
                    ->where('is_approved', true)
                    ->orWhereIn('status', [
                        ContractPerformanceAct::STATUS_APPROVED,
                        ContractPerformanceAct::STATUS_SIGNED,
                    ]);
            })
            ->with('contract')
            ->orderBy('id')
            ->get();
        foreach ($acts as $act) {
            $recognizedAt = $act->signed_at ?? $act->approval_date?->toImmutable()->startOfDay();
            if ($recognizedAt === null) {
                continue;
            }
            $reconciliation = $this->reconciler->reconcile(
                $act,
                CarbonImmutable::instance($recognizedAt),
            );
            $eventIds = [...$eventIds, ...$reconciliation['event_ids']];
        }
        sort($eventIds, SORT_NUMERIC);

        return array_values(array_unique($eventIds, SORT_NUMERIC));
    }
}
