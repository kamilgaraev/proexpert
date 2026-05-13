<?php

namespace App\Repositories;

use App\Models\Material;
use App\Repositories\Interfaces\MaterialRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MaterialRepository extends BaseRepository implements MaterialRepositoryInterface
{
    /**
     * Конструктор репозитория материалов
     */
    public function __construct()
    {
        parent::__construct(Material::class); // Передаем имя класса
    }

    /**
     * Получить материалы для определенной организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsForOrganization(int $organizationId)
    {
        return $this->model->where('organization_id', $organizationId)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить материал со связанными данными
     *
     * @param int $id
     * @param array $relations Связи для загрузки
     * @return \App\Models\Material|null
     */
    public function findWithRelations(int $id, array $relations = [])
    {
        return $this->model->with($relations)->find($id);
    }

    /**
     * Получить активные материалы организации
     *
     * @param int $organizationId
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getActiveMaterials(int $organizationId): Collection
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }
    
    /**
     * Получить материалы по категории
     *
     * @param int $organizationId
     * @param string $category
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getMaterialsByCategory(int $organizationId, string $category)
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('category', $category)
            ->where('is_active', true)
            ->with('measurementUnit')
            ->orderBy('name')
            ->get();
    }

    /**
     * Получить материалы для организации с фильтрацией и пагинацией.
     *
     * @param int $organizationId
     * @param int $perPage
     * @param array $filters ['name' => string, 'category' => string, 'is_active' => bool]
     * @param string $sortBy
     * @param string $sortDirection
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getMaterialsForOrganizationPaginated(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'name',
        string $sortDirection = 'asc'
    ): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $this->model->where('organization_id', $organizationId)
                             ->with('measurementUnit');

        // Применяем фильтры
        $likeOperator = $this->caseInsensitiveLikeOperator();

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $query->where(function ($query) use ($likeOperator, $searchTerm): void {
                $query->where('name', $likeOperator, $searchTerm)
                    ->orWhere('code', $likeOperator, $searchTerm)
                    ->orWhere('external_code', $likeOperator, $searchTerm);
            });
        }
        if (!empty($filters['name'])) {
            $query->where('name', $likeOperator, '%' . $filters['name'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', $likeOperator, '%' . $filters['category'] . '%');
        }
        if (!empty($filters['measurement_unit_id'])) {
            $query->where('measurement_unit_id', (int)$filters['measurement_unit_id']);
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        // Применяем сортировку
        $query->orderBy($sortBy, $sortDirection);

        // Пагинация
        return $query->paginate($perPage);
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    // Implementations for methods from the old RepositoryInterface
    public function all(array $columns = ['*']): Collection
    {
        return parent::getAll($columns);
    }

    public function find(int $modelId, array $columns = ['*'], array $relations = [], array $appends = []): ?Material
    {
        return parent::find($modelId, $columns, $relations, $appends);
    }

    public function findBy(string $field, mixed $value, array $columns = ['*']): Collection
    {
        return $this->model->where($field, $value)->get($columns);
    }

    public function delete(int $id): bool
    {
        return parent::delete($id);
    }
    // End of RepositoryInterface methods

    // Заглушки для методов интерфейса MaterialRepositoryInterface
    public function findByExternalCode(string $externalCode, int $organizationId): ?Material
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('external_code', $externalCode)
            ->whereNull('deleted_at')
            ->first();
    }

    public function findByNameAndOrganization(string $name, int $organizationId): ?Material
    {
        return $this->model->where('organization_id', $organizationId)
            ->where('name', $name)
            ->whereNull('deleted_at')
            ->first();
    }

    public function getMaterialUsageByProjects(int $organizationId, array $projectIds, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = DB::table('warehouse_movements as wm')
            ->leftJoin('projects as p', 'wm.project_id', '=', 'p.id')
            ->where('wm.organization_id', $organizationId)
            ->where('wm.movement_type', 'write_off')
            ->select([
                DB::raw('COALESCE(p.id, 0) as project_id'),
                DB::raw("COALESCE(p.name, 'Без проекта') as project_name"),
                DB::raw('SUM(wm.quantity * COALESCE(wm.price, 0)) as total_cost'),
                DB::raw('SUM(wm.quantity) as total_quantity'),
                DB::raw('COUNT(DISTINCT wm.material_id) as materials_count'),
            ])
            ->groupBy(['p.id', 'p.name']);

        if (!empty($projectIds)) {
            $query->whereIn('wm.project_id', $projectIds);
        }

        if ($dateFrom) {
            $query->whereDate('wm.movement_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('wm.movement_date', '<=', $dateTo);
        }

        return collect($query->get())->map(fn ($item) => [
            'project_id' => (int) $item->project_id,
            'project_name' => (string) $item->project_name,
            'total_cost' => (float) $item->total_cost,
            'total_quantity' => (float) $item->total_quantity,
            'materials_count' => (int) $item->materials_count,
        ]);
    }

    public function getMaterialUsageBySuppliers(int $organizationId, array $supplierIds, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = DB::table('warehouse_movements as wm')
            ->join('materials as m', 'wm.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('wm.organization_id', $organizationId)
            ->whereIn('wm.movement_type', ['receipt', 'return'])
            ->select([
                DB::raw('0 as supplier_id'),
                DB::raw("'Без поставщика' as supplier_name"),
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(wm.quantity) as total_received'),
                DB::raw('0 as total_used'),
                DB::raw('AVG(wm.price) as average_price'),
                DB::raw('COUNT(*) as operations_count')
            ])
            ->groupBy(['m.id', 'm.name', 'mu.short_name']);

        if (!empty($supplierIds)) {
            return collect();
        }

        if ($dateFrom) {
            $query->whereDate('wm.movement_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('wm.movement_date', '<=', $dateTo);
        }

        return collect($query->get());
    }

    public function getMaterialUsageSummary(int $organizationId, ?string $dateFrom, ?string $dateTo): Collection
    {
        $balanceQuery = DB::table('warehouse_balances as wb')
            ->where('wb.organization_id', $organizationId);

        $movementQuery = DB::table('warehouse_movements as wm')
            ->where('wm.organization_id', $organizationId);

        if ($dateFrom) {
            $movementQuery->whereDate('wm.movement_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $movementQuery->whereDate('wm.movement_date', '<=', $dateTo);
        }

        $summary = (clone $balanceQuery)->select([
            DB::raw('COUNT(DISTINCT material_id) as materials_count'),
            DB::raw('SUM(available_quantity * COALESCE(unit_price, 0)) as inventory_value'),
            DB::raw('SUM(CASE WHEN min_stock_level > 0 AND available_quantity <= min_stock_level THEN 1 ELSE 0 END) as low_stock_count'),
        ])->first();

        $topMaterials = (clone $balanceQuery)
            ->join('materials as m', 'wb.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->select([
                'm.name',
                DB::raw('SUM(wb.available_quantity * COALESCE(wb.unit_price, 0)) as total_cost'),
                DB::raw('SUM(wb.available_quantity) as total_quantity'),
                DB::raw('MAX(mu.short_name) as unit'),
            ])
            ->groupBy(['m.id', 'm.name'])
            ->orderByDesc('total_cost')
            ->limit(5)
            ->get()
            ->map(fn ($item) => [
                'name' => (string) $item->name,
                'total_cost' => (float) $item->total_cost,
                'total_quantity' => (float) $item->total_quantity,
                'unit' => $item->unit,
            ]);

        return collect([
            'total_inventory_value' => (float) ($summary->inventory_value ?? 0),
            'total_materials_count' => (int) ($summary->materials_count ?? 0),
            'low_stock_count' => (int) ($summary->low_stock_count ?? 0),
            'recent_movements_count' => (int) $movementQuery->count(),
            'top_materials' => $topMaterials->values(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]);
    }

    public function searchByNameOrCode(int $organizationId, string $searchTerm): Collection
    {
        $searchTerm = '%' . trim($searchTerm) . '%';
        
        return $this->model
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', $searchTerm)
                  ->orWhere('code', 'like', $searchTerm)
                  ->orWhere('external_code', 'like', $searchTerm);
            })
            ->with(['measurementUnit'])
            ->limit(50)
            ->get();
    }

    public function findByIds(array $ids, int $organizationId): Collection
    {
        return $this->model
            ->whereIn('id', $ids)
            ->where('organization_id', $organizationId)
            ->with(['measurementUnit'])
            ->get();
    }

    public function getMaterialsWithLowStock(int $organizationId, int $threshold): Collection
    {
        return collect(DB::table('warehouse_balances as wb')
            ->join('materials as m', 'wb.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('wb.organization_id', $organizationId)
            ->groupBy(['m.id', 'm.name', 'm.code', 'm.category', 'mu.short_name'])
            ->havingRaw('SUM(wb.available_quantity) <= COALESCE(NULLIF(MAX(wb.min_stock_level), 0), ?)', [$threshold])
            ->select([
                'm.id',
                'm.name',
                'm.code as sku',
                'm.category as category_name',
                'mu.short_name as unit',
                DB::raw('SUM(wb.available_quantity) as current_stock'),
                DB::raw('MAX(wb.min_stock_level) as minimum_stock'),
                DB::raw('MAX(wb.max_stock_level) as max_stock_level')
            ])
            ->get())
            ->map(fn ($item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'sku' => $item->sku,
                'category_name' => $item->category_name,
                'current_stock' => (float) $item->current_stock,
                'minimum_stock' => (float) ($item->minimum_stock ?: $threshold),
                'unit' => $item->unit,
                'reorder_quantity' => max(0, (float) ($item->minimum_stock ?: $threshold) - (float) $item->current_stock),
            ]);
    }

    public function getMostUsedMaterials(int $organizationId, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = DB::table('warehouse_movements as wm')
            ->join('materials as m', 'wm.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('wm.organization_id', $organizationId)
            ->where('wm.movement_type', 'write_off');

        if ($dateFrom) {
            $query->whereDate('wm.movement_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('wm.movement_date', '<=', $dateTo);
        }

        return collect($query
            ->groupBy(['m.id', 'm.name', 'mu.short_name'])
            ->orderBy('total_used', 'desc')
            ->limit($limit)
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(wm.quantity) as total_used'),
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('AVG(wm.price) as average_price')
            ])
            ->get());
    }

    public function getMaterialReceiptsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        $query = DB::table('warehouse_movements as wm')
            ->leftJoin('projects as p', 'wm.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'wm.user_id', '=', 'u.id')
            ->where('wm.material_id', $materialId)
            ->whereIn('wm.movement_type', ['receipt', 'return'])
            ->orderBy('wm.movement_date', 'desc')
            ->select([
                'wm.id',
                'wm.quantity',
                'wm.price',
                DB::raw('wm.quantity * COALESCE(wm.price, 0) as total_amount'),
                'wm.document_number',
                'wm.movement_date as receipt_date',
                'wm.reason as notes',
                DB::raw('NULL as supplier_name'),
                'p.name as project_name',
                'u.name as user_name'
            ]);

        return $query->paginate($perPage);
    }

    public function getMaterialWriteOffsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        $query = DB::table('warehouse_movements as wm')
            ->leftJoin('projects as p', 'wm.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'wm.user_id', '=', 'u.id')
            ->where('wm.material_id', $materialId)
            ->where('wm.movement_type', 'write_off')
            ->orderBy('wm.movement_date', 'desc')
            ->select([
                'wm.id',
                'wm.quantity',
                'wm.price as unit_price',
                DB::raw('wm.quantity * COALESCE(wm.price, 0) as total_price'),
                'wm.movement_date as usage_date',
                'wm.reason as work_description',
                'wm.reason as notes',
                'p.name as project_name',
                'u.name as user_name',
                DB::raw('NULL as work_type_name')
            ]);

        return $query->paginate($perPage);
    }

    public function updateOrCreateFromAccounting(array $data, int $organizationId): ?Material
    {
        try {
            if (empty($data['external_code'])) {
                Log::warning('Cannot update or create material from accounting: external_code is required', [
                    'data' => $data,
                    'organization_id' => $organizationId
                ]);
                return null;
            }

            $criteria = [
                'organization_id' => $organizationId,
                'external_code' => $data['external_code']
            ];

            $materialData = [
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'description' => $data['description'] ?? null,
                'measurement_unit_id' => $data['measurement_unit_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'accounting_code' => $data['accounting_code'] ?? null,
                'sbis_nomenclature_code' => $data['sbis_nomenclature_code'] ?? null,
                'sbis_unit_code' => $data['sbis_unit_code'] ?? null,
                'updated_at' => now()
            ];

            $material = $this->model->updateOrCreate($criteria, $materialData);

            Log::info('Material updated/created from accounting system', [
                'material_id' => $material->id,
                'external_code' => $data['external_code'],
                'organization_id' => $organizationId
            ]);

            return $material;
        } catch (\Exception $e) {
            Log::error('Failed to update or create material from accounting', [
                'data' => $data,
                'organization_id' => $organizationId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    public function getAllMaterialNames(int $organizationId): Collection
    {
        return $this->model
            ->where('organization_id', $organizationId)
            ->select(['id', 'name', 'code'])
            ->orderBy('name')
            ->get()
            ->pluck('name', 'id');
    }

    public function getMaterialCostHistory(int $organizationId, int $materialId, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = DB::table('warehouse_movements as wm')
            ->where('wm.organization_id', $organizationId)
            ->where('wm.material_id', $materialId)
            ->whereNotNull('wm.price');

        if ($dateFrom) {
            $query->whereDate('wm.movement_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('wm.movement_date', '<=', $dateTo);
        }

        return collect($query
            ->orderBy('wm.movement_date', 'desc')
            ->select([
                'wm.movement_date as usage_date',
                'wm.price as unit_price',
                'wm.movement_type as operation_type',
                'wm.quantity',
                DB::raw('wm.quantity * COALESCE(wm.price, 0) as total_price')
            ])
            ->get());
    }

    public function getAverageMaterialCost(int $materialId): ?float
    {
        $result = DB::table('warehouse_movements')
            ->where('material_id', $materialId)
            ->whereNotNull('price')
            ->selectRaw('AVG(price) as average_cost')
            ->first();
        
        return $result && $result->average_cost ? (float) $result->average_cost : null;
    }

    public function getMaterialMovementReport(int $organizationId, array $filters): Collection
    {
        $query = DB::table('materials as m')
            ->leftJoin('warehouse_movements as wm', 'm.id', '=', 'wm.material_id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('m.organization_id', $organizationId);

        if (!empty($filters['material_ids'])) {
            $query->whereIn('m.id', $filters['material_ids']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('wm.movement_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('wm.movement_date', '<=', $filters['date_to']);
        }

        $movements = $query
            ->groupBy(['m.id', 'm.name', 'mu.short_name'])
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(CASE WHEN wm.movement_type IN (\'receipt\', \'return\') THEN wm.quantity ELSE 0 END) as total_received'),
                DB::raw('SUM(CASE WHEN wm.movement_type = \'write_off\' THEN wm.quantity ELSE 0 END) as total_used'),
                DB::raw('COUNT(CASE WHEN wm.movement_type IN (\'receipt\', \'return\') THEN 1 END) as receipt_operations'),
                DB::raw('COUNT(CASE WHEN wm.movement_type = \'write_off\' THEN 1 END) as writeoff_operations'),
                DB::raw('MIN(wm.movement_date) as first_operation'),
                DB::raw('MAX(wm.movement_date) as last_operation')
            ])
            ->get();

        return collect([
            'movements' => $movements,
            'summary' => [
                'total_materials' => $movements->count(),
                'total_received' => $movements->sum('total_received'),
                'total_used' => $movements->sum('total_used'),
                'net_movement' => $movements->sum('total_received') - $movements->sum('total_used')
            ],
            'filters_applied' => $filters
        ]);
    }

    public function getInventoryReport(int $organizationId, array $filters): Collection
    {
        $query = DB::table('materials as m')
            ->leftJoin('warehouse_balances as wb', 'm.id', '=', 'wb.material_id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('m.organization_id', $organizationId);

        if (!empty($filters['category_ids'])) {
            $query->whereIn('m.category', $filters['category_ids']);
        }

        $inventory = $query
            ->groupBy(['m.id', 'm.name', 'm.code', 'm.category', 'mu.short_name'])
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'm.code as material_code',
                'mu.short_name as unit',
                'm.category as category_name',
                DB::raw('0 as total_received'),
                DB::raw('0 as total_used'),
                DB::raw('COALESCE(SUM(wb.available_quantity), 0) as current_stock'),
                DB::raw('AVG(wb.unit_price) as average_cost'),
                DB::raw('MAX(wb.last_movement_at) as last_movement_date')
            ])
            ->get();

        return collect([
            'inventory' => $inventory,
            'summary' => [
                'total_materials' => $inventory->count(),
                'materials_in_stock' => $inventory->where('current_stock', '>', 0)->count(),
                'materials_out_of_stock' => $inventory->where('current_stock', '<=', 0)->count(),
                'total_stock_value' => $inventory->sum(function ($item) {
                    return $item->current_stock * ($item->average_cost ?? 0);
                })
            ],
            'filters_applied' => $filters
        ]);
    }

    public function getMaterialCostDynamicsReport(int $organizationId, array $filters): Collection
    { 
        $query = DB::table('warehouse_movements as wm')
            ->join('materials as m', 'wm.material_id', '=', 'm.id')
            ->where('wm.organization_id', $organizationId)
            ->whereNotNull('wm.price');

        if (!empty($filters['material_ids'])) {
            $query->whereIn('m.id', $filters['material_ids']);
        }
        if (!empty($filters['date_from'])) {
            $query->whereDate('wm.movement_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('wm.movement_date', '<=', $filters['date_to']);
        }

        $dynamics = $query
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                DB::raw('DATE(wm.movement_date) as operation_date'),
                DB::raw('AVG(wm.price) as avg_price'),
                DB::raw('MIN(wm.price) as min_price'),
                DB::raw('MAX(wm.price) as max_price'),
                DB::raw('COUNT(*) as operations_count')
            ])
            ->groupBy(['m.id', 'm.name', DB::raw('DATE(wm.movement_date)')])
            ->orderBy('m.name')
            ->orderBy('operation_date')
            ->get();

        return collect([
            'dynamics' => $dynamics,
            'summary' => [
                'materials_tracked' => $dynamics->pluck('material_id')->unique()->count(),
                'date_range' => [
                    'from' => $dynamics->min('operation_date'),
                    'to' => $dynamics->max('operation_date')
                ],
                'total_operations' => $dynamics->sum('operations_count')
            ],
            'filters_applied' => $filters
        ]);
    }
}
