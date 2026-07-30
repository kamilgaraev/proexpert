<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\Support\Reporting\ReportSourceAccessPolicy;
use App\Support\Reporting\OwnerReportFilterApplier;
use App\Support\Reporting\SourceReadinessResult;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

final readonly class InventoryRiskReadinessProbe implements ReportDefinitionReadinessProbe
{
    public function __construct(
        private ReportSourceAccessPolicy $sourceAccess,
        private OwnerReportFilterApplier $filters,
    ) {}

    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'inventory_risk'
            && $definition->formulaVersion === 'inventory-planning.v1';
    }

    public function assertReady(ReportExecutionContext $context, ReportQuery $query): void
    {
        $this->inspect($context, $query)->assertReady('inventory_risk');
    }

    public function inspect(ReportExecutionContext $context, ReportQuery $query): SourceReadinessResult
    {
        $projects = $context->scope->projectIds;
        $allowedWarehouseIds = $this->sourceAccess->allowedIds(
            $context->scope->resources,
            'warehouse',
        );
        $eligibleQuery = WarehouseMovement::query()
            ->join('materials as readiness_inventory_material', 'readiness_inventory_material.id', '=', 'warehouse_movements.material_id')
            ->where('warehouse_movements.organization_id', $context->scope->organizationId)
            ->when(
                $allowedWarehouseIds !== null,
                static fn ($builder) => $builder->whereIn('warehouse_movements.warehouse_id', $allowedWarehouseIds),
            )
            ->when($projects !== [], static fn ($builder) => $builder->whereIn('warehouse_movements.project_id', $projects))
            ->where('warehouse_movements.movement_date', '<=', $query->asOf);
        $this->filters->apply($eligibleQuery, $this->filters->only($query->filters, [
            'organization', 'organization_id', 'warehouse', 'warehouse_id', 'project', 'project_id',
            'material', 'material_id', 'category', 'abc', 'xyz', 'period',
        ]), [
            'organization' => 'warehouse_movements.organization_id',
            'organization_id' => 'warehouse_movements.organization_id',
            'warehouse' => 'warehouse_movements.warehouse_id',
            'warehouse_id' => 'warehouse_movements.warehouse_id',
            'project' => 'warehouse_movements.project_id',
            'project_id' => 'warehouse_movements.project_id',
            'material' => 'warehouse_movements.material_id',
            'material_id' => 'warehouse_movements.material_id',
            'category' => 'readiness_inventory_material.category',
            'abc' => DB::raw("readiness_inventory_material.additional_properties->>'abc_class'"),
            'xyz' => DB::raw("readiness_inventory_material.additional_properties->>'xyz_class'"),
            'period' => 'warehouse_movements.movement_date',
        ]);
        $eligible = $eligibleQuery->count('warehouse_movements.id');
        $events = WarehouseInventoryEvent::query()
            ->join('materials as readiness_event_material', 'readiness_event_material.id', '=', 'warehouse_inventory_events.material_id')
            ->where('warehouse_inventory_events.organization_id', $context->scope->organizationId)
            ->when(
                $allowedWarehouseIds !== null,
                static fn ($builder) => $builder->whereIn('warehouse_inventory_events.warehouse_id', $allowedWarehouseIds),
            )
            ->when($projects !== [], static fn ($builder) => $builder->whereIn('warehouse_inventory_events.project_id', $projects))
            ->where('warehouse_inventory_events.occurred_at', '<=', $query->asOf);
        $this->filters->apply($events, $this->filters->only($query->filters, [
            'organization', 'organization_id', 'warehouse', 'warehouse_id', 'project', 'project_id',
            'material', 'material_id', 'category', 'abc', 'xyz', 'period',
        ]), [
            'organization' => 'warehouse_inventory_events.organization_id',
            'organization_id' => 'warehouse_inventory_events.organization_id',
            'warehouse' => 'warehouse_inventory_events.warehouse_id',
            'warehouse_id' => 'warehouse_inventory_events.warehouse_id',
            'project' => 'warehouse_inventory_events.project_id',
            'project_id' => 'warehouse_inventory_events.project_id',
            'material' => 'warehouse_inventory_events.material_id',
            'material_id' => 'warehouse_inventory_events.material_id',
            'category' => 'readiness_event_material.category',
            'abc' => DB::raw("readiness_event_material.additional_properties->>'abc_class'"),
            'xyz' => DB::raw("readiness_event_material.additional_properties->>'xyz_class'"),
            'period' => 'warehouse_inventory_events.occurred_at',
        ]);
        $projected = (clone $events)->distinct()->count('source_movement_id');
        $unknown = (clone $events)
            ->where(function ($builder): void {
                $builder->whereIn('unit_dimension', ['', 'unknown'])
                    ->orWhereIn('unit_code', ['', 'unknown'])
                    ->orWhereIn('conversion_version', ['', 'unknown', 'unproven'])
                    ->orWhere(function ($valuation): void {
                        $valuation->whereIn('event_type', ['receipt', 'issue', 'reserved_issue', 'return'])
                            ->whereNull('unit_price_minor');
                    });
            })
            ->count();
        $missingOpening = (clone $events)
            ->whereNull('opening_basis')
            ->whereNotExists(function ($builder): void {
                $builder->selectRaw('1')
                    ->from('warehouse_inventory_events as earlier')
                    ->whereColumn('earlier.organization_id', 'warehouse_inventory_events.organization_id')
                    ->whereColumn('earlier.warehouse_id', 'warehouse_inventory_events.warehouse_id')
                    ->whereRaw('earlier.project_id IS NOT DISTINCT FROM warehouse_inventory_events.project_id')
                    ->whereColumn('earlier.material_id', 'warehouse_inventory_events.material_id')
                    ->whereColumn('earlier.unit_dimension', 'warehouse_inventory_events.unit_dimension')
                    ->whereColumn('earlier.unit_code', 'warehouse_inventory_events.unit_code')
                    ->whereColumn('earlier.conversion_version', 'warehouse_inventory_events.conversion_version')
                    ->where(function ($position): void {
                        $position
                            ->whereColumn('earlier.occurred_at', '<', 'warehouse_inventory_events.occurred_at')
                            ->orWhere(function ($sameTime): void {
                                $sameTime
                                    ->whereColumn(
                                        'earlier.occurred_at',
                                        'warehouse_inventory_events.occurred_at',
                                    )
                                    ->whereColumn('earlier.id', '<', 'warehouse_inventory_events.id');
                            });
                    });
            })
            ->count();
        $invalidTransfers = DB::query()
            ->fromSub(
                WarehouseInventoryEvent::query()
                    ->where('organization_id', $context->scope->organizationId)
                    ->when(
                        $allowedWarehouseIds !== null,
                        static fn ($builder) => $builder->whereIn(
                            'warehouse_id',
                            $allowedWarehouseIds,
                        ),
                    )
                    ->when($projects !== [], static fn ($builder) => $builder->whereIn('project_id', $projects))
                    ->where('occurred_at', '<=', $query->asOf)
                    ->whereNotNull('transfer_pair_key')
                    ->select('transfer_pair_key')
                    ->groupBy('transfer_pair_key')
                    ->havingRaw('COUNT(*) < 2 OR SUM(on_hand_delta) <> 0'),
                'invalid_transfer_pairs',
            )
            ->count();

        return new SourceReadinessResult(
            $eligible,
            $projected,
            max(0, $eligible - $projected) + $missingOpening + $invalidTransfers,
            $unknown,
            (clone $events)->where('source_version', '<', 1)->count(),
            (clone $events)->whereRaw('LENGTH(source_hash) <> 64')->count(),
            new DateTimeImmutable,
        );
    }
}
