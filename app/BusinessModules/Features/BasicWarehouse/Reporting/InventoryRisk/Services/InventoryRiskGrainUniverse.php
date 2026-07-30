<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\DTO\InventoryRiskGrain;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryDemandSnapshot;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\InventoryReorderPolicyVersion;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\OwnerReportFilterApplier;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class InventoryRiskGrainUniverse
{
    public function __construct(private OwnerReportFilterApplier $filters) {}

    public function collect(
        int $organizationId,
        DateTimeImmutable $asOf,
        ?array $allowedWarehouseIds,
        array $projectIds,
        ReportFilterSet $reportFilters,
        ?Collection $pinnedEvents = null,
    ): Collection {
        $events = $pinnedEvents ?? WarehouseInventoryEvent::query()
            ->where('inventory_demand_snapshots.organization_id', $organizationId)
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
            ->join('materials as inventory_demand_filter_material', 'inventory_demand_filter_material.id', '=', 'inventory_demand_snapshots.material_id')
            ->where('inventory_demand_snapshots.organization_id', $organizationId)
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
            ;
        $this->applyFilters(
            $demands,
            $reportFilters,
            'inventory_demand_snapshots',
            'inventory_demand_filter_material',
        );
        $demands = $demands->select('inventory_demand_snapshots.*')->get();

        $policies = InventoryReorderPolicyVersion::query()
            ->join('materials as inventory_policy_filter_material', 'inventory_policy_filter_material.id', '=', 'inventory_reorder_policy_versions.material_id')
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
            ;
        $this->applyFilters(
            $policies,
            $reportFilters,
            'inventory_reorder_policy_versions',
            'inventory_policy_filter_material',
        );
        $policies = $policies->select('inventory_reorder_policy_versions.*')->get();

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

    private function applyFilters(
        Builder $query,
        ReportFilterSet $reportFilters,
        string $table,
        string $materialAlias,
    ): void {
        $this->filters->apply($query, $this->filters->only($reportFilters, [
            'organization', 'organization_id', 'warehouse', 'warehouse_id', 'project', 'project_id',
            'material', 'material_id', 'category', 'abc', 'xyz',
        ]), [
            'organization' => $table.'.organization_id',
            'organization_id' => $table.'.organization_id',
            'warehouse' => $table.'.warehouse_id',
            'warehouse_id' => $table.'.warehouse_id',
            'project' => $table.'.project_id',
            'project_id' => $table.'.project_id',
            'material' => $table.'.material_id',
            'material_id' => $table.'.material_id',
            'category' => $materialAlias.'.category',
            'abc' => DB::raw($materialAlias.".additional_properties->>'abc_class'"),
            'xyz' => DB::raw($materialAlias.".additional_properties->>'xyz_class'"),
        ]);
    }
}
