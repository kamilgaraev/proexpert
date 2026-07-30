<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryRiskGrain;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryDemandSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryReorderPolicyVersion;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class InventoryRiskGrainUniverse
{
    public function collect(
        int $organizationId,
        DateTimeImmutable $asOf,
        ?array $allowedWarehouseIds,
        array $projectIds,
        ?Collection $pinnedEvents = null,
    ): Collection {
        $events = $pinnedEvents ?? WarehouseInventoryEvent::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->when(
                $allowedWarehouseIds !== null,
                static fn (Builder $builder): Builder => $builder->whereIn('warehouse_id', $allowedWarehouseIds),
            )
            ->when(
                $projectIds !== [],
                static fn (Builder $builder): Builder => $builder->whereIn('project_id', $projectIds),
            )
            ->get();

        $demands = InventoryDemandSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $asOf)
            ->where(static fn (Builder $builder): Builder => $builder
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $asOf))
            ->when(
                $allowedWarehouseIds !== null,
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('warehouse_id')
                        ->orWhereIn('warehouse_id', $allowedWarehouseIds),
                ),
            )
            ->when(
                $projectIds !== [],
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('project_id')
                        ->orWhereIn('project_id', $projectIds),
                ),
            )
            ->get();

        $policies = InventoryReorderPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->where('effective_from', '<=', $asOf)
            ->where(static fn (Builder $builder): Builder => $builder
                ->whereNull('effective_to')
                ->orWhere('effective_to', '>', $asOf))
            ->when(
                $allowedWarehouseIds !== null,
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('warehouse_id')
                        ->orWhereIn('warehouse_id', $allowedWarehouseIds),
                ),
            )
            ->when(
                $projectIds !== [],
                static fn (Builder $builder): Builder => $builder->where(
                    static fn (Builder $scope): Builder => $scope
                        ->whereNull('project_id')
                        ->orWhereIn('project_id', $projectIds),
                ),
            )
            ->get();

        $grains = collect();
        foreach ([$events, $demands, $policies] as $sources) {
            foreach ($sources as $source) {
                $warehouseId = $source->getAttribute('warehouse_id');
                $materialId = $source->getAttribute('material_id');
                if ($warehouseId === null || $materialId === null) {
                    continue;
                }
                $grain = new InventoryRiskGrain(
                    (int) $warehouseId,
                    $source->getAttribute('project_id') === null
                        ? null
                        : (int) $source->getAttribute('project_id'),
                    (int) $materialId,
                    (string) $source->getAttribute('unit_dimension'),
                    (string) $source->getAttribute('unit_code'),
                    (string) $source->getAttribute('conversion_version'),
                );
                $grains->put($grain->key(), $grain);
            }
        }

        return $grains;
    }
}
