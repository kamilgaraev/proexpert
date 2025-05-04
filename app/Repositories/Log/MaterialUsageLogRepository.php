<?php

namespace App\Repositories\Log;

use App\Models\Models\Log\MaterialUsageLog;
use App\Repositories\BaseRepository;
use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class MaterialUsageLogRepository extends BaseRepository implements MaterialUsageLogRepositoryInterface
{
    /**
     * Конструктор репозитория логов использования материалов
     */
    public function __construct()
    {
        parent::__construct(MaterialUsageLog::class);
    }

    /**
     * Получить агрегированные данные по расходу материалов.
     *
     * @param int $organizationId
     * @param array $filters (project_id, material_id, user_id, date_from, date_to)
     * @return Collection
     */
    public function getAggregatedUsage(int $organizationId, array $filters = []): Collection
    {
        $query = $this->model->select(
            'material_usage_logs.material_id',
            'material_usage_logs.project_id',
            'materials.name as material_name',
            'projects.name as project_name',
            DB::raw('SUM(material_usage_logs.quantity) as total_quantity'),
            'materials.measurement_unit_id' // Добавим ID ед.изм.
        )
        ->join('projects', 'material_usage_logs.project_id', '=', 'projects.id')
        ->join('materials', 'material_usage_logs.material_id', '=', 'materials.id')
        ->where('projects.organization_id', $organizationId)
        ->groupBy(
            'material_usage_logs.material_id',
            'material_usage_logs.project_id',
            'materials.name',
            'projects.name',
            'materials.measurement_unit_id'
        );

        // Применяем фильтры
        if (!empty($filters['project_id'])) {
            $query->where('material_usage_logs.project_id', $filters['project_id']);
        }
        if (!empty($filters['material_id'])) {
            $query->where('material_usage_logs.material_id', $filters['material_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('material_usage_logs.user_id', $filters['user_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('material_usage_logs.usage_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('material_usage_logs.usage_date', '<=', $filters['date_to']);
        }

        // Загружаем единицы измерения отдельно для оптимизации
        $results = $query->orderBy('projects.name')->orderBy('materials.name')->get();

        // Загружаем единицы измерения одним запросом
        $unitIds = $results->pluck('measurement_unit_id')->unique()->filter();
        if ($unitIds->isNotEmpty()) {
            $units = DB::table('measurement_units')->whereIn('id', $unitIds)->pluck('symbol', 'id');
            // Добавляем символ единицы измерения к результатам
            $results->each(function ($item) use ($units) {
                $item->unit_symbol = $units->get($item->measurement_unit_id);
            });
        }

        return $results;
    }

    /**
     * Получить пагинированный список логов использования материалов для организации.
     */
    public function getPaginatedLogs(
        int $organizationId,
        int $perPage = 15,
        array $filters = [],
        string $sortBy = 'usage_date',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator
    {
        $query = $this->model->with(['project', 'material.measurementUnit', 'user']) // Загружаем связи
            ->whereHas('project', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });

        // Применяем фильтры
        if (!empty($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
        if (!empty($filters['material_id'])) {
            $query->where('material_id', $filters['material_id']);
        }
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }
        if (!empty($filters['date_from'])) {
            $query->where('usage_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('usage_date', '<=', $filters['date_to']);
        }

        // TODO: Добавить валидацию $sortBy
        $query->orderBy($sortBy, $sortDirection);

        return $query->paginate($perPage);
    }

    // Реализация специфичных методов, если они будут добавлены в интерфейс
} 