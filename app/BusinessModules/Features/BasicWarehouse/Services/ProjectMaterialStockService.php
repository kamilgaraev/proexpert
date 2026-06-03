<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\Models\JournalMaterial;
use App\Models\User;
use Illuminate\Support\Collection;

class ProjectMaterialStockService
{
    public function getProjectStock(int $organizationId, ?int $projectId = null, ?User $user = null): array
    {
        $deliveries = ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('status', ProjectMaterialDeliveryStatusEnum::ACCEPTED->value)
            ->when($projectId !== null && $projectId > 0, fn ($query) => $query->where('project_id', $projectId))
            ->when(
                $user !== null && !$user->isOrganizationAdmin($organizationId),
                function ($query) use ($user): void {
                    $query->whereHas('project.users', function ($usersQuery) use ($user): void {
                        $usersQuery->where('users.id', $user->id);
                    });
                }
            )
            ->with([
                'project',
                'material.measurementUnit',
                'warehouse',
                'projectWarehouse',
                'journalMaterials.journalEntry.journal',
            ])
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get();

        $warehouseQuantities = $this->warehouseQuantities($organizationId, $deliveries);
        $items = $deliveries
            ->groupBy(fn (ProjectMaterialDelivery $delivery): string => $this->stockKey($delivery))
            ->map(fn (Collection $materialDeliveries): array => $this->mapMaterialStock($materialDeliveries, $warehouseQuantities))
            ->sortBy([
                ['project.name', 'asc'],
                ['material.name', 'asc'],
            ])
            ->values()
            ->values();

        return [
            'items' => $items->all(),
            'summary' => [
                'materials_count' => $items->count(),
                'deliveries_count' => $deliveries->count(),
                'accepted_quantity' => round((float) $items->sum('accepted_quantity'), 3),
                'on_project_quantity' => round((float) $items->sum('on_project_quantity'), 3),
                'issued_quantity' => round((float) $items->sum('issued_quantity'), 3),
                'used_quantity' => round((float) $items->sum('used_quantity'), 3),
                'available_quantity' => round((float) $items->sum('available_quantity'), 3),
            ],
        ];
    }

    private function stockKey(ProjectMaterialDelivery $delivery): string
    {
        return implode(':', [
            $delivery->project_id ?? 0,
            $delivery->material_id ?? 0,
        ]);
    }

    private function mapMaterialStock(Collection $deliveries, array $warehouseQuantities): array
    {
        /** @var ProjectMaterialDelivery $first */
        $first = $deliveries->first();

        $acceptedQuantity = (float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => (float) $delivery->accepted_quantity);
        $usedQuantity = (float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => $this->usedQuantity($delivery));
        $legacyAvailableQuantity = max(0.0, $acceptedQuantity - $usedQuantity);
        $quantities = $warehouseQuantities[$this->stockKey($first)] ?? $this->emptyWarehouseQuantities();
        $onProjectQuantity = (float) $quantities['on_project_quantity'];
        $issuedQuantity = (float) $quantities['issued_quantity'];
        $hasWarehouseFlow = $onProjectQuantity > 0
            || $issuedQuantity > 0
            || $deliveries->contains(
                fn (ProjectMaterialDelivery $delivery): bool => $delivery->project_warehouse_id !== null
                    || $delivery->outbound_movement_id !== null
                    || $delivery->inbound_movement_id !== null
            );
        $availableQuantity = $hasWarehouseFlow
            ? $onProjectQuantity + $issuedQuantity
            : $legacyAvailableQuantity;

        return [
            'project' => $first->project ? [
                'id' => $first->project->id,
                'name' => $first->project->name,
            ] : null,
            'material' => $first->material ? [
                'id' => $first->material->id,
                'name' => $first->material->name,
                'code' => $first->material->code,
                'measurement_unit' => $first->material->relationLoaded('measurementUnit') && $first->material->measurementUnit ? [
                    'id' => $first->material->measurementUnit->id,
                    'name' => $first->material->measurementUnit->name,
                    'short_name' => $first->material->measurementUnit->short_name,
                ] : null,
            ] : null,
            'accepted_quantity' => round($acceptedQuantity, 3),
            'on_project_quantity' => round($onProjectQuantity, 3),
            'issued_quantity' => round($issuedQuantity, 3),
            'used_quantity' => round($usedQuantity, 3),
            'available_quantity' => round($availableQuantity, 3),
            'deliveries' => $deliveries
                ->map(fn (ProjectMaterialDelivery $delivery): array => $this->mapDelivery($delivery))
                ->values()
                ->all(),
            'journal_usages' => $deliveries
                ->flatMap(fn (ProjectMaterialDelivery $delivery): Collection => $delivery->journalMaterials->map(
                    fn (JournalMaterial $material): array => $this->mapJournalUsage($delivery, $material)
                ))
                ->sortByDesc('entry_date')
                ->values()
                ->all(),
        ];
    }

    private function mapDelivery(ProjectMaterialDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'source_type' => $delivery->source_type,
            'status' => $delivery->status?->value,
            'status_label' => $delivery->status?->label(),
            'accepted_quantity' => (float) $delivery->accepted_quantity,
            'used_quantity' => round($this->usedQuantity($delivery), 3),
            'available_quantity' => round($this->availableQuantity($delivery), 3),
            'accepted_at' => $delivery->accepted_at?->toDateTimeString(),
            'warehouse' => $delivery->warehouse ? [
                'id' => $delivery->warehouse->id,
                'name' => $delivery->warehouse->name,
            ] : null,
            'project_warehouse' => $delivery->projectWarehouse ? [
                'id' => $delivery->projectWarehouse->id,
                'name' => $delivery->projectWarehouse->name,
                'warehouse_type' => $delivery->projectWarehouse->warehouse_type,
            ] : null,
            'linked_entities' => [
                'allocation_id' => $delivery->warehouse_project_allocation_id,
                'site_request_id' => $delivery->site_request_id,
                'purchase_request_id' => $delivery->purchase_request_id,
                'purchase_order_id' => $delivery->purchase_order_id,
                'project_warehouse_id' => $delivery->project_warehouse_id,
            ],
        ];
    }

    private function mapJournalUsage(ProjectMaterialDelivery $delivery, JournalMaterial $material): array
    {
        $entry = $material->journalEntry;

        return [
            'delivery_id' => $delivery->id,
            'journal_material_id' => $material->id,
            'journal_entry_id' => $entry?->id,
            'entry_number' => $entry?->entry_number,
            'entry_date' => $entry?->entry_date?->toDateString(),
            'work_description' => $entry?->work_description,
            'quantity' => (float) $material->quantity,
            'measurement_unit' => $material->measurement_unit,
            'notes' => $material->notes,
        ];
    }

    private function usedQuantity(ProjectMaterialDelivery $delivery): float
    {
        return (float) $delivery->journalMaterials->sum(fn (JournalMaterial $material): float => (float) $material->quantity);
    }

    private function availableQuantity(ProjectMaterialDelivery $delivery): float
    {
        return max(0.0, (float) $delivery->accepted_quantity - $this->usedQuantity($delivery));
    }

    private function warehouseQuantities(int $organizationId, Collection $deliveries): array
    {
        $projectIds = $deliveries->pluck('project_id')->filter()->unique()->values();
        $materialIds = $deliveries->pluck('material_id')->filter()->unique()->values();

        if ($projectIds->isEmpty() || $materialIds->isEmpty()) {
            return [];
        }

        $balances = WarehouseBalance::query()
            ->where('warehouse_balances.organization_id', $organizationId)
            ->whereIn('warehouse_balances.material_id', $materialIds)
            ->whereHas('warehouse', function ($query) use ($organizationId, $projectIds): void {
                $query->where('organization_id', $organizationId)
                    ->where('is_active', true)
                    ->whereIn('project_id', $projectIds)
                    ->whereIn('warehouse_type', [
                        OrganizationWarehouse::TYPE_PROJECT,
                        OrganizationWarehouse::TYPE_CUSTODY,
                    ]);
            })
            ->with('warehouse:id,project_id,warehouse_type')
            ->get();

        $quantities = [];

        foreach ($balances as $balance) {
            $warehouse = $balance->warehouse;

            if (!$warehouse || !$warehouse->project_id) {
                continue;
            }

            $key = implode(':', [
                $warehouse->project_id,
                $balance->material_id,
            ]);
            $quantities[$key] ??= $this->emptyWarehouseQuantities();

            if ($warehouse->warehouse_type === OrganizationWarehouse::TYPE_PROJECT) {
                $quantities[$key]['on_project_quantity'] += (float) $balance->available_quantity;
                continue;
            }

            if ($warehouse->warehouse_type === OrganizationWarehouse::TYPE_CUSTODY) {
                $quantities[$key]['issued_quantity'] += (float) $balance->available_quantity;
            }
        }

        return $quantities;
    }

    private function emptyWarehouseQuantities(): array
    {
        return [
            'on_project_quantity' => 0.0,
            'issued_quantity' => 0.0,
        ];
    }
}
