<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryActItem;

final class InventoryActPayloadPresenter
{
    public function __construct(private readonly WarehousePersonIdentityResolver $personIdentityResolver) {}

    public function present(int $organizationId, InventoryAct $act, bool $includeItems = false): array
    {
        return $this->presentMany($organizationId, [$act], $includeItems)[0];
    }

    public function presentMany(int $organizationId, iterable $acts, bool $includeItems = false): array
    {
        $actList = [];
        $references = [];
        $referenceIds = [];

        foreach ($acts as $act) {
            $actIndex = count($actList);
            $actList[] = $act;

            if ($act->created_by !== null) {
                $referenceId = count($references);
                $references[$referenceId] = [
                    'user_id' => (int) $act->created_by,
                    'date' => $act->created_at ?? $act->inventory_date,
                ];
                $referenceIds[$actIndex]['creator'] = $referenceId;
            }

            if ($act->approved_by !== null) {
                $referenceId = count($references);
                $references[$referenceId] = [
                    'user_id' => (int) $act->approved_by,
                    'date' => $act->approved_at ?? $act->updated_at ?? $act->created_at,
                ];
                $referenceIds[$actIndex]['approver'] = $referenceId;
            }
        }

        $identities = $this->personIdentityResolver->resolveMany($organizationId, $references);

        return array_map(
            fn (InventoryAct $act, int $actIndex): array => $this->makeActPayload(
                $act,
                $includeItems,
                isset($referenceIds[$actIndex]['creator'])
                    ? $identities[$referenceIds[$actIndex]['creator']]
                    : null,
                isset($referenceIds[$actIndex]['approver'])
                    ? $identities[$referenceIds[$actIndex]['approver']]
                    : null,
            ),
            $actList,
            array_keys($actList),
        );
    }

    public function presentItem(InventoryActItem $item): array
    {
        $material = $item->material;
        $measurementUnit = $material?->measurementUnit;

        return [
            'id' => $item->id,
            'inventory_act_id' => $item->inventory_act_id,
            'material_id' => $item->material_id,
            'expected_quantity' => (float) $item->expected_quantity,
            'actual_quantity' => $item->actual_quantity !== null ? (float) $item->actual_quantity : null,
            'difference_quantity' => $item->difference !== null ? (float) $item->difference : null,
            'unit_price' => (float) $item->unit_price,
            'difference_value' => $item->total_value !== null ? (float) $item->total_value : null,
            'cell_id' => $item->cell_id,
            'cell' => $item->cell ? [
                'id' => $item->cell->id,
                'code' => $item->cell->code,
                'name' => $item->cell->name,
                'full_address' => $item->cell->full_address,
                'zone' => $item->cell->zone ? [
                    'id' => $item->cell->zone->id,
                    'code' => $item->cell->zone->code,
                    'name' => $item->cell->zone->name,
                ] : null,
            ] : null,
            'storage_address' => $item->cell?->full_address ?? $item->location_code,
            'location_code' => $item->location_code,
            'batch_number' => $item->batch_number,
            'notes' => $item->notes,
            'material' => $material ? [
                'id' => $material->id,
                'name' => $material->name,
                'unit' => $measurementUnit?->short_name ?? $measurementUnit?->name,
                'article' => $material->code,
            ] : null,
        ];
    }

    private function makeActPayload(
        InventoryAct $act,
        bool $includeItems,
        ?array $creatorIdentity,
        ?array $approverIdentity,
    ): array {
        $payload = [
            'id' => $act->id,
            'act_number' => $act->act_number,
            'warehouse_id' => $act->warehouse_id,
            'status' => $act->status,
            'inventory_date' => optional($act->inventory_date)?->toDateString(),
            'created_by' => $act->created_by,
            'commission_members' => $act->commission_members ?? [],
            'started_at' => optional($act->started_at)?->toDateTimeString(),
            'completed_at' => optional($act->completed_at)?->toDateTimeString(),
            'approved_at' => optional($act->approved_at)?->toDateTimeString(),
            'approved_by' => $act->approved_by,
            'notes' => $act->notes,
            'summary' => $act->summary ?? [
                'total_items' => (int) ($act->getAttribute('items_count')
                    ?? ($act->relationLoaded('items') ? $act->items->count() : 0)),
                'items_with_discrepancy' => (int) ($act->getAttribute('items_with_discrepancy_count')
                    ?? ($act->relationLoaded('items')
                        ? $act->items->filter(fn (InventoryActItem $item) => $item->hasDiscrepancy())->count()
                        : 0)),
                'total_difference_value' => (float) ($act->getAttribute('items_total_difference_value')
                    ?? ($act->relationLoaded('items')
                        ? $act->items->sum(fn (InventoryActItem $item) => (float) ($item->total_value ?? 0))
                        : 0)),
            ],
            'warehouse' => $act->warehouse ? [
                'id' => $act->warehouse->id,
                'name' => $act->warehouse->name,
            ] : null,
            'creator' => $this->makePersonPayload($act->created_by, $creatorIdentity),
            'approver' => $this->makePersonPayload($act->approved_by, $approverIdentity),
        ];

        if ($includeItems) {
            $payload['items'] = $act->items
                ->map(fn (InventoryActItem $item) => $this->presentItem($item))
                ->values()
                ->all();
        }

        return $payload;
    }

    private function makePersonPayload(mixed $userId, ?array $identity): ?array
    {
        if ($userId === null || $identity === null) {
            return null;
        }

        return [
            'id' => (int) $userId,
            'name' => $identity['name'],
        ];
    }
}
