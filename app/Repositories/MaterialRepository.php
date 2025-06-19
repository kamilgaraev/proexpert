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
        if (!empty($filters['name'])) {
            $query->where('name', 'ilike', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['category'])) {
            $query->where('category', 'ilike', '%' . $filters['category'] . '%');
        }
        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool)$filters['is_active']);
        }

        // Применяем сортировку
        $query->orderBy($sortBy, $sortDirection);

        // Пагинация
        return $query->paginate($perPage);
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
        $query = DB::table('material_usage_logs as mul')
            ->join('materials as m', 'mul.material_id', '=', 'm.id')
            ->join('projects as p', 'mul.project_id', '=', 'p.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('mul.organization_id', $organizationId)
            ->select([
                'p.id as project_id',
                'p.name as project_name',
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END) as total_received'),
                DB::raw('SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END) as total_used'),
                DB::raw('COUNT(*) as operations_count'),
                DB::raw('MAX(mul.usage_date) as last_operation_date')
            ])
            ->groupBy(['p.id', 'p.name', 'm.id', 'm.name', 'mu.short_name']);

        if (!empty($projectIds)) {
            $query->whereIn('p.id', $projectIds);
        }

        if ($dateFrom) {
            $query->where('mul.usage_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('mul.usage_date', '<=', $dateTo);
        }

        return collect($query->get());
    }

    public function getMaterialUsageBySuppliers(int $organizationId, array $supplierIds, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = DB::table('material_usage_logs as mul')
            ->join('materials as m', 'mul.material_id', '=', 'm.id')
            ->leftJoin('suppliers as s', 'mul.supplier_id', '=', 's.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('mul.organization_id', $organizationId)
            ->select([
                's.id as supplier_id',
                's.name as supplier_name',
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END) as total_received'),
                DB::raw('SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END) as total_used'),
                DB::raw('AVG(mul.unit_price) as average_price'),
                DB::raw('COUNT(*) as operations_count')
            ])
            ->groupBy(['s.id', 's.name', 'm.id', 'm.name', 'mu.short_name']);

        if (!empty($supplierIds)) {
            $query->whereIn('s.id', $supplierIds);
        }

        if ($dateFrom) {
            $query->where('mul.usage_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('mul.usage_date', '<=', $dateTo);
        }

        return collect($query->get());
    }

    public function getMaterialUsageSummary(int $organizationId, ?string $dateFrom, ?string $dateTo): Collection
    {
        $query = DB::table('material_usage_logs as mul')
            ->join('materials as m', 'mul.material_id', '=', 'm.id')
            ->where('mul.organization_id', $organizationId);

        if ($dateFrom) {
            $query->where('mul.usage_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('mul.usage_date', '<=', $dateTo);
        }

        $summary = $query->select([
            DB::raw('COUNT(DISTINCT m.id) as unique_materials_count'),
            DB::raw('COUNT(*) as total_operations'),
            DB::raw('SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END) as total_received'),
            DB::raw('SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END) as total_used'),
            DB::raw('SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.total_price ELSE 0 END) as total_received_value'),
            DB::raw('SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.total_price ELSE 0 END) as total_used_value')
        ])->first();

        return collect([
            'unique_materials_count' => $summary->unique_materials_count ?? 0,
            'total_operations' => $summary->total_operations ?? 0,
            'total_received' => $summary->total_received ?? 0,
            'total_used' => $summary->total_used ?? 0,
            'current_balance' => ($summary->total_received ?? 0) - ($summary->total_used ?? 0),
            'total_received_value' => $summary->total_received_value ?? 0,
            'total_used_value' => $summary->total_used_value ?? 0,
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
        return collect(DB::table('materials as m')
            ->leftJoin('material_usage_logs as mul', function ($join) {
                $join->on('m.id', '=', 'mul.material_id');
            })
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('m.organization_id', $organizationId)
            ->groupBy(['m.id', 'm.name', 'm.code', 'mu.short_name'])
            ->havingRaw('(COALESCE(SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END), 0)) <= ?', [$threshold])
            ->select([
                'm.id',
                'm.name',
                'm.code',
                'mu.short_name as unit',
                DB::raw('COALESCE(SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END), 0) as current_stock'),
                DB::raw('MAX(mul.usage_date) as last_operation_date')
            ])
            ->get());
    }

    public function getMostUsedMaterials(int $organizationId, int $limit = 10, ?string $dateFrom = null, ?string $dateTo = null): Collection
    {
        $query = DB::table('material_usage_logs as mul')
            ->join('materials as m', 'mul.material_id', '=', 'm.id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('mul.organization_id', $organizationId)
            ->where('mul.operation_type', 'write_off');

        if ($dateFrom) {
            $query->where('mul.usage_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('mul.usage_date', '<=', $dateTo);
        }

        return collect($query
            ->groupBy(['m.id', 'm.name', 'mu.short_name'])
            ->orderBy('total_used', 'desc')
            ->limit($limit)
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(mul.quantity) as total_used'),
                DB::raw('COUNT(*) as usage_count'),
                DB::raw('AVG(mul.unit_price) as average_price')
            ])
            ->get());
    }

    public function getMaterialReceiptsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        $query = DB::table('material_receipts as mr')
            ->leftJoin('suppliers as s', 'mr.supplier_id', '=', 's.id')
            ->leftJoin('projects as p', 'mr.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'mr.user_id', '=', 'u.id')
            ->where('mr.material_id', $materialId)
            ->orderBy('mr.receipt_date', 'desc')
            ->select([
                'mr.id',
                'mr.quantity',
                'mr.price',
                'mr.total_amount',
                'mr.document_number',
                'mr.receipt_date',
                'mr.notes',
                's.name as supplier_name',
                'p.name as project_name',
                'u.name as user_name'
            ]);

        return $query->paginate($perPage);
    }

    public function getMaterialWriteOffsHistory(int $materialId, int $perPage = 15): LengthAwarePaginator
    {
        $query = DB::table('material_usage_logs as mul')
            ->leftJoin('projects as p', 'mul.project_id', '=', 'p.id')
            ->leftJoin('users as u', 'mul.user_id', '=', 'u.id')
            ->leftJoin('work_types as wt', 'mul.work_type_id', '=', 'wt.id')
            ->where('mul.material_id', $materialId)
            ->where('mul.operation_type', 'write_off')
            ->orderBy('mul.usage_date', 'desc')
            ->select([
                'mul.id',
                'mul.quantity',
                'mul.unit_price',
                'mul.total_price',
                'mul.usage_date',
                'mul.work_description',
                'mul.notes',
                'p.name as project_name',
                'u.name as user_name',
                'wt.name as work_type_name'
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
        $query = DB::table('material_usage_logs as mul')
            ->where('mul.organization_id', $organizationId)
            ->where('mul.material_id', $materialId)
            ->whereNotNull('mul.unit_price');

        if ($dateFrom) {
            $query->where('mul.usage_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('mul.usage_date', '<=', $dateTo);
        }

        return collect($query
            ->orderBy('mul.usage_date', 'desc')
            ->select([
                'mul.usage_date',
                'mul.unit_price',
                'mul.operation_type',
                'mul.quantity',
                'mul.total_price'
            ])
            ->get());
    }

    public function getAverageMaterialCost(int $materialId): ?float
    {
        $result = DB::table('material_usage_logs')
            ->where('material_id', $materialId)
            ->whereNotNull('unit_price')
            ->selectRaw('AVG(unit_price) as average_cost')
            ->first();
        
        return $result && $result->average_cost ? (float) $result->average_cost : null;
    }

    public function getMaterialMovementReport(int $organizationId, array $filters): Collection
    {
        $query = DB::table('materials as m')
            ->leftJoin('material_usage_logs as mul', 'm.id', '=', 'mul.material_id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->where('m.organization_id', $organizationId);

        if (!empty($filters['material_ids'])) {
            $query->whereIn('m.id', $filters['material_ids']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('mul.usage_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('mul.usage_date', '<=', $filters['date_to']);
        }

        $movements = $query
            ->groupBy(['m.id', 'm.name', 'mu.short_name'])
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'mu.short_name as unit',
                DB::raw('SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END) as total_received'),
                DB::raw('SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END) as total_used'),
                DB::raw('COUNT(CASE WHEN mul.operation_type = \'receipt\' THEN 1 END) as receipt_operations'),
                DB::raw('COUNT(CASE WHEN mul.operation_type = \'write_off\' THEN 1 END) as writeoff_operations'),
                DB::raw('MIN(mul.usage_date) as first_operation'),
                DB::raw('MAX(mul.usage_date) as last_operation')
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
            ->leftJoin('material_usage_logs as mul', 'm.id', '=', 'mul.material_id')
            ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
            ->leftJoin('cost_categories as cc', 'm.category_id', '=', 'cc.id')
            ->where('m.organization_id', $organizationId);

        if (!empty($filters['category_ids'])) {
            $query->whereIn('m.category_id', $filters['category_ids']);
        }

        $inventory = $query
            ->groupBy(['m.id', 'm.name', 'm.code', 'mu.short_name', 'cc.name'])
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                'm.code as material_code',
                'mu.short_name as unit',
                'cc.name as category_name',
                DB::raw('COALESCE(SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END), 0) as total_received'),
                DB::raw('COALESCE(SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END), 0) as total_used'),
                DB::raw('COALESCE(SUM(CASE WHEN mul.operation_type = \'receipt\' THEN mul.quantity ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN mul.operation_type = \'write_off\' THEN mul.quantity ELSE 0 END), 0) as current_stock'),
                DB::raw('AVG(CASE WHEN mul.operation_type = \'receipt\' THEN mul.unit_price END) as average_cost'),
                DB::raw('MAX(mul.usage_date) as last_movement_date')
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
        $query = DB::table('material_usage_logs as mul')
            ->join('materials as m', 'mul.material_id', '=', 'm.id')
            ->where('mul.organization_id', $organizationId)
            ->whereNotNull('mul.unit_price');

        if (!empty($filters['material_ids'])) {
            $query->whereIn('m.id', $filters['material_ids']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('mul.usage_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('mul.usage_date', '<=', $filters['date_to']);
        }

        $dynamics = $query
            ->select([
                'm.id as material_id',
                'm.name as material_name',
                DB::raw('DATE(mul.usage_date) as operation_date'),
                DB::raw('AVG(mul.unit_price) as avg_price'),
                DB::raw('MIN(mul.unit_price) as min_price'),
                DB::raw('MAX(mul.unit_price) as max_price'),
                DB::raw('COUNT(*) as operations_count')
            ])
            ->groupBy(['m.id', 'm.name', DB::raw('DATE(mul.usage_date)')])
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