<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Queries;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\Builder;

class ContractorMaterialTransferQuery
{
    public function build(int $organizationId, array $filters): Builder
    {
        $query = WarehouseMovement::query()
            ->where('organization_id', $organizationId)
            ->where('metadata->is_contractor_transfer', true)
            ->whereIn('movement_type', [WarehouseMovement::TYPE_WRITE_OFF, WarehouseMovement::TYPE_TRANSFER_OUT])
            ->whereHas('warehouse', static fn (Builder $scope) => $scope->withTrashed()->where('organization_id', $organizationId))
            ->whereHas('material', static fn (Builder $scope) => $scope->withTrashed()->where('organization_id', $organizationId));

        foreach (['warehouse_id', 'material_id', 'project_id'] as $key) {
            if (isset($filters[$key])) {
                $query->where($key, (int) $filters[$key]);
            }
        }
        if (isset($filters['contractor_id'])) {
            $query->where('metadata->contractor_id', (int) $filters['contractor_id']);
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('movement_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('movement_date', '<=', $filters['date_to']);
        }
        if (isset($filters['search']) && trim($filters['search']) !== '') {
            $pattern = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
            $query->where(static function (Builder $scope) use ($pattern, $organizationId): void {
                $scope->whereRaw("metadata->>'contractor_name' ILIKE ?", [$pattern])
                    ->orWhereRaw("metadata->>'contractor_id' IN (SELECT id::text FROM contractors WHERE organization_id = ? AND name ILIKE ?)", [$organizationId, $pattern])
                    ->orWhereHas('material', static fn (Builder $material) => $material->withTrashed()->where('name', 'ilike', $pattern));
            });
        }

        return $query;
    }

    public function summary(int $organizationId, array $filters): array
    {
        $totals = $this->build($organizationId, $filters)->toBase()->selectRaw(
            "COUNT(*) AS transfers_count, COUNT(DISTINCT metadata->>'contractor_id') AS contractors_count, "
            .'COUNT(DISTINCT material_id) AS materials_count, COALESCE(SUM(ROUND(quantity * price, 2)), 0) AS total_value'
        )->first();

        return [
            'transfers_count' => (int) $totals->transfers_count,
            'contractors_count' => (int) $totals->contractors_count,
            'materials_count' => (int) $totals->materials_count,
            'total_value' => (float) $totals->total_value,
        ];
    }
}
