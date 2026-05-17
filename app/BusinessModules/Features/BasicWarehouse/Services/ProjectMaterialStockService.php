<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
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
                'journalMaterials.journalEntry.journal',
            ])
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->get();

        $items = $deliveries
            ->groupBy(fn (ProjectMaterialDelivery $delivery): string => $this->stockKey($delivery))
            ->map(fn (Collection $materialDeliveries): array => $this->mapMaterialStock($materialDeliveries))
            ->sortBy([
                ['project.name', 'asc'],
                ['material.name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'summary' => [
                'materials_count' => count($items),
                'deliveries_count' => $deliveries->count(),
                'accepted_quantity' => round((float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => (float) $delivery->accepted_quantity), 3),
                'used_quantity' => round((float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => $this->usedQuantity($delivery)), 3),
                'available_quantity' => round((float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => $this->availableQuantity($delivery)), 3),
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

    private function mapMaterialStock(Collection $deliveries): array
    {
        /** @var ProjectMaterialDelivery $first */
        $first = $deliveries->first();

        $acceptedQuantity = (float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => (float) $delivery->accepted_quantity);
        $usedQuantity = (float) $deliveries->sum(fn (ProjectMaterialDelivery $delivery): float => $this->usedQuantity($delivery));
        $availableQuantity = max(0.0, $acceptedQuantity - $usedQuantity);

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
            'linked_entities' => [
                'allocation_id' => $delivery->warehouse_project_allocation_id,
                'site_request_id' => $delivery->site_request_id,
                'purchase_request_id' => $delivery->purchase_request_id,
                'purchase_order_id' => $delivery->purchase_order_id,
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
}
