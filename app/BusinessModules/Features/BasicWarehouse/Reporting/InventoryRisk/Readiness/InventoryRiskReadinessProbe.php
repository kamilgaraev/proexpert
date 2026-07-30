<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Readiness;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Models\WarehouseInventoryEvent;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\InventoryRiskGrainUniverse;
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
        private InventoryRiskGrainUniverse $grainUniverse,
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
            'material', 'material_id', 'category', 'abc', 'xyz',
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
        ]);
        $pinnedEvents = (clone $events)
            ->select('warehouse_inventory_events.*')
            ->get();
        $grains = $this->grainUniverse->collect(
            $context->scope->organizationId,
            $query->asOf,
            $allowedWarehouseIds,
            $projects,
            $pinnedEvents,
        );
        $eligible = $grains->count();
        $projected = $eligible;
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
        $openingGrains = $pinnedEvents
            ->filter(static fn (WarehouseInventoryEvent $event): bool => in_array(
                $event->opening_basis,
                ['verified_zero', 'opening_inventory', 'prior_verified_closing'],
                true,
            ))
            ->mapWithKeys(static fn (WarehouseInventoryEvent $event): array => [
                implode(':', [
                    $event->warehouse_id,
                    $event->project_id ?? 'null',
                    $event->material_id,
                    $event->unit_dimension,
                    $event->unit_code,
                    $event->conversion_version,
                ]) => true,
            ]);
        $missingOpening = $grains
            ->keys()
            ->reject(static fn (string $grain): bool => $openingGrains->has($grain))
            ->count();
        $invalidTransfers = DB::query()
            ->fromSub(
                WarehouseInventoryEvent::query()
                    ->where('organization_id', $context->scope->organizationId)
                    ->where('occurred_at', '<=', $query->asOf)
                    ->whereIn(
                        'transfer_pair_key',
                        (clone $events)
                            ->whereNotNull('transfer_pair_key')
                            ->select('warehouse_inventory_events.transfer_pair_key'),
                    )
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
