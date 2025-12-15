<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\Admin\DashboardService;
use App\Services\Admin\DashboardExportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Models\Project;
use App\Enums\Contract\ContractStatusEnum;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    protected DashboardService $dashboardService;
    protected DashboardExportService $exportService;

    public function __construct(DashboardService $dashboardService, DashboardExportService $exportService)
    {
        $this->dashboardService = $dashboardService;
        $this->exportService = $exportService;
        // Авторизация настроена на уровне роутов через middleware стек
    }

    /**
     * Получить полную структуру дашборда (упрощенный подход)
     * Возвращает все данные дашборда в одной структуре без необходимости настройки виджетов
     */
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        // Валидация обязательного параметра project_id
        $request->validate([
            'project_id' => 'required|integer|min:1',
        ]);
        
        $projectId = (int)$request->input('project_id');

        // Используем упрощенный подход - возвращаем всю структуру дашборда
        $dashboard = $this->dashboardService->getFullDashboard($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $dashboard
        ]);
    }

    /**
     * Получить только сводную информацию (для обратной совместимости)
     */
    public function summary(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        $request->validate([
            'project_id' => 'required|integer|min:1',
        ]);
        
        $projectId = (int)$request->input('project_id');

        $summary = $this->dashboardService->getSummary($organizationId, $projectId);
        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * Временной ряд по выбранной метрике
     */
    public function timeseries(Request $request): JsonResponse
    {
        $metric = $request->input('metric', 'users');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        
        $data = $this->dashboardService->getTimeseries($metric, $period, $organizationId, $projectId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Топ сущностей по активности/объёму
     */
    public function topEntities(Request $request): JsonResponse
    {
        $entity = $request->input('entity', 'projects');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $limit = (int)$request->input('limit', 5);
        $sortBy = $request->input('sort_by', 'amount');
        
        $data = $this->dashboardService->getTopEntities($entity, $period, $organizationId, $projectId, $limit, $sortBy);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * История последних действий/операций
     */
    public function history(Request $request): JsonResponse
    {
        $type = $request->input('type', 'materials');
        $limit = (int)$request->input('limit', 10);
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $status = $request->input('status');
        
        $data = $this->dashboardService->getHistory($type, $limit, $organizationId, $projectId, $status);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Лимиты и их заполнение
     */
    public function limits(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);
        $data = $this->dashboardService->getLimits($organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Получить контракты проекта требующие внимания
     */
    public function contractsRequiringAttention(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Валидация project_id
        $request->validate(['project_id' => 'required|integer|min:1']);
        $projectId = (int)$request->input('project_id');
        
        $contracts = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->with(['project:id,name', 'contractor:id,name'])
            ->leftJoin(DB::raw('(SELECT contract_id, COALESCE(SUM(total_amount), 0) as completed_amount 
                               FROM completed_works 
                               WHERE status = \'confirmed\' 
                               GROUP BY contract_id) as cw'), 'contracts.id', '=', 'cw.contract_id')
            ->select('contracts.*', DB::raw('COALESCE(cw.completed_amount, 0) as completed_amount'))
            ->addSelect(DB::raw('CASE 
                WHEN contracts.total_amount > 0 
                THEN ROUND((COALESCE(cw.completed_amount, 0) / contracts.total_amount) * 100, 2)
                ELSE 0 
                END as completion_percentage'))
            ->where(function($query) {
                $query->whereRaw('(CASE 
                    WHEN contracts.total_amount > 0 
                    THEN ROUND((COALESCE(cw.completed_amount, 0) / contracts.total_amount) * 100, 2)
                    ELSE 0 
                    END) >= 90')
                ->orWhere(function($subQuery) {
                    $subQuery->where('end_date', '<', now())
                             ->where('status', ContractStatusEnum::ACTIVE->value);
                });
            })
            ->orderByDesc(DB::raw('CASE 
                WHEN (CASE 
                    WHEN contracts.total_amount > 0 
                    THEN ROUND((COALESCE(cw.completed_amount, 0) / contracts.total_amount) * 100, 2)
                    ELSE 0 
                    END) >= 100 THEN 3
                WHEN end_date < CURRENT_TIMESTAMP AND status = \'' . ContractStatusEnum::ACTIVE->value . '\' THEN 2  
                WHEN (CASE 
                    WHEN contracts.total_amount > 0 
                    THEN ROUND((COALESCE(cw.completed_amount, 0) / contracts.total_amount) * 100, 2)
                    ELSE 0 
                    END) >= 90 THEN 1
                ELSE 0 
                END'))
            ->limit(50)
            ->get()
            ->map(function ($contract) {
                $completionPercentage = (float) $contract->completion_percentage;
                $completedAmount = (float) $contract->completed_amount;
                $totalAmount = (float) $contract->total_amount;
                
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'project_name' => $contract->project?->name,
                    'contractor_name' => $contract->contractor?->name,
                    'total_amount' => $totalAmount,
                    'completed_works_amount' => $completedAmount,
                    'completion_percentage' => $completionPercentage,
                    'remaining_amount' => max(0, $totalAmount - $completedAmount),
                    'status' => $contract->status->value,
                    'end_date' => $contract->end_date?->format('Y-m-d'),
                    'is_nearing_limit' => $completionPercentage >= 90.0,
                    'is_overdue' => $contract->is_overdue,
                    'is_completed' => $completionPercentage >= 100,
                    'attention_reason' => $this->getAttentionReason($contract, $completionPercentage),
                    'priority' => $this->getContractPriority($contract, $completionPercentage),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $contracts
        ]);
    }

    /**
     * Получить общую статистику по контрактам проекта
     */
    public function contractsStatistics(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Валидация project_id
        $request->validate(['project_id' => 'required|integer|min:1']);
        $projectId = (int)$request->input('project_id');
        
        // Получаем контракты с учетом мультипроектных
        $contracts = Contract::where('organization_id', $organizationId)
            ->where(function($q) use ($projectId) {
                $q->where('project_id', $projectId)
                  ->orWhereExists(function($sub) use ($projectId) {
                      $sub->select(DB::raw(1))
                          ->from('contract_project')
                          ->whereColumn('contract_project.contract_id', 'contracts.id')
                          ->where('contract_project.project_id', $projectId);
                  });
            })
            ->whereNull('deleted_at')
            ->get();
        
        $stats = (object)[
            'total_contracts' => $contracts->count(),
            'active_contracts' => $contracts->where('status', ContractStatusEnum::ACTIVE)->count(),
            'completed_contracts' => $contracts->where('status', ContractStatusEnum::COMPLETED)->count(),
            'draft_contracts' => $contracts->where('status', ContractStatusEnum::DRAFT)->count(),
            'total_amount' => $this->dashboardService->calculateTotalAmountForContracts($contracts, $projectId),
            'avg_amount' => 0,
        ];
        
        $stats->avg_amount = $stats->total_contracts > 0 ? $stats->total_amount / $stats->total_contracts : 0;

        // Статистика по выполненным работам проекта
        $worksStats = DB::table('completed_works')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total_works,
                COUNT(CASE WHEN status = ? THEN 1 END) as confirmed_works,
                SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END) as confirmed_amount
            ', ['confirmed', 'confirmed'])
            ->first();

        // Контракты проекта требующие внимания
        $contractsNeedingAttention = Contract::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->get()
            ->filter(function ($contract) {
                return $contract->isNearingLimit() || 
                       $contract->completion_percentage >= 100 ||
                       ($contract->end_date && $contract->end_date->isPast() && $contract->status === ContractStatusEnum::ACTIVE);
            })
            ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'contracts' => [
                    'total' => $stats->total_contracts,
                    'active' => $stats->active_contracts,
                    'completed' => $stats->completed_contracts,
                    'draft' => $stats->draft_contracts,
                    'requiring_attention' => $contractsNeedingAttention,
                    'total_amount' => (float) $stats->total_amount,
                    'avg_amount' => (float) $stats->avg_amount,
                ],
                'completed_works' => [
                    'total' => $worksStats->total_works,
                    'confirmed' => $worksStats->confirmed_works,
                    'confirmed_amount' => (float) $worksStats->confirmed_amount,
                ]
            ]
        ]);
    }

    /**
     * Получить топ контрактов проекта по объему
     */
    public function topContracts(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $limit = (int)$request->input('limit', 5);

        $data = $this->dashboardService->getTopContractsByAmount($organizationId, $projectId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить активность по выполненным работам проекта (последние 30 дней)
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Валидация project_id
        $request->validate(['project_id' => 'required|integer|min:1']);
        $projectId = (int)$request->input('project_id');
        
        $days = $request->query('days', 30);

        $activity = CompletedWork::where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('created_at', '>=', Carbon::now()->subDays($days)->toDateTimeString())
            ->with(['project:id,name', 'workType:id,name', 'user:id,name', 'contract:id,number'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($work) {
                return [
                    'id' => $work->id,
                    'project_name' => $work->project?->name,
                    'contract_number' => $work->contract?->number,
                    'work_type_name' => $work->workType?->name,
                    'user_name' => $work->user?->name,
                    'quantity' => (float) $work->quantity,
                    'total_amount' => (float) $work->total_amount,
                    'status' => $work->status,
                    'completion_date' => $work->completion_date->format('Y-m-d'),
                    'created_at' => $work->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $activity
        ]);
    }

    /**
     * Получить финансовые метрики
     */
    public function financialMetrics(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getFinancialMetrics($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить детальную аналитику контрактов
     */
    public function contractsAnalytics(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $filters = $request->only(['status', 'contractor_id', 'date_from', 'date_to']);

        $data = $this->dashboardService->getContractsAnalytics($organizationId, $projectId, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить аналитику проектов
     */
    public function projectsAnalytics(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        $filters = $request->only(['status', 'is_archived']);
        $data = $this->dashboardService->getProjectsAnalytics($organizationId, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить аналитику материалов
     */
    public function materialsAnalytics(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        $filters = $request->only(['category', 'is_active']);
        $data = $this->dashboardService->getMaterialsAnalytics($organizationId, $filters);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить сравнение периодов
     */
    public function comparison(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id') ?? (Auth::user()->current_organization_id ?? null);

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context is required.'
            ], 400);
        }

        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $period = $request->input('period', 'month');

        $data = $this->dashboardService->getComparisonData($organizationId, $projectId, $period);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить распределение контрактов по статусам
     */
    public function contractsByStatus(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getContractsByStatus($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить распределение проектов по статусам
     */
    public function projectsByStatus(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        $data = $this->dashboardService->getProjectsByStatus($organizationId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить контракты по подрядчикам
     */
    public function contractsByContractor(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $limit = (int)$request->input('limit', 10);

        $data = $this->dashboardService->getContractsByContractor($organizationId, $projectId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить материалы по проектам
     */
    public function materialsByProject(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $limit = (int)$request->input('limit', 10);

        $data = $this->dashboardService->getMaterialsByProject($organizationId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }


    /**
     * Получить топ проектов
     */
    public function topProjects(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $limit = (int)$request->input('limit', 5);

        $data = $this->dashboardService->getTopProjectsByBudget($organizationId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить топ материалов
     */
    public function topMaterials(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $limit = (int)$request->input('limit', 5);

        $data = $this->dashboardService->getTopMaterialsByUsage($organizationId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить топ подрядчиков
     */
    public function topContractors(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $limit = (int)$request->input('limit', 5);

        $data = $this->dashboardService->getTopContractorsByVolume($organizationId, $limit);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить аналитику выполненных работ
     */
    public function completedWorksAnalytics(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getCompletedWorksAnalytics($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить месячные тренды
     */
    public function monthlyTrends(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getMonthlyTrends($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить движение финансов
     */
    public function financialFlow(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $period = $request->input('period', 'month');

        $data = $this->dashboardService->getFinancialFlow($organizationId, $projectId, $period);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить производительность контрактов
     */
    public function contractPerformance(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getContractPerformance($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить прогресс проектов
     */
    public function projectProgress(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getProjectProgress($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить расход материалов
     */
    public function materialConsumption(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $period = $request->input('period', 'month');

        $data = $this->dashboardService->getMaterialConsumption($organizationId, $projectId, $period);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить эффективность работ
     */
    public function worksEfficiency(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getWorksEfficiency($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить материалы по категориям
     */
    public function materialsByCategory(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;

        $data = $this->dashboardService->getMaterialsByCategory($organizationId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Получить работы по типам
     */
    public function worksByType(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;

        $data = $this->dashboardService->getWorksByType($organizationId, $projectId);
        
        return response()->json([
            'success' => true,
            'data' => $data
        ]);
    }

    /**
     * Экспорт сводки дашборда
     */
    public function exportSummary(Request $request): Response
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $format = $request->input('format', 'excel'); // excel или csv

        if ($format === 'excel') {
            $filePath = $this->exportService->exportSummary($organizationId, $projectId);
            $fileName = 'dashboard_summary_' . date('Y-m-d') . '.xlsx';
            
            return response()->download($filePath, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        }

        // CSV формат
        $summary = $this->dashboardService->getSummary($organizationId, $projectId ?? 0);
        $data = [];
        $headers = ['Показатель', 'Значение'];

        foreach ($summary['summary'] as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (!is_array($subValue)) {
                        $data[] = [ucfirst($key) . ' - ' . ucfirst($subKey), $subValue];
                    }
                }
            } else {
                $data[] = [ucfirst($key), $value];
            }
        }

        $filePath = $this->exportService->exportToCsv($data, $headers);
        $fileName = 'dashboard_summary_' . date('Y-m-d') . '.csv';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Экспорт контрактов
     */
    public function exportContracts(Request $request): Response
    {
        $organizationId = Auth::user()->current_organization_id;
        $projectId = $request->input('project_id') ? (int)$request->input('project_id') : null;
        $filters = $request->only(['status']);

        $filePath = $this->exportService->exportContracts($organizationId, $projectId, $filters);
        $fileName = 'contracts_export_' . date('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Экспорт проектов
     */
    public function exportProjects(Request $request): Response
    {
        $organizationId = Auth::user()->current_organization_id;
        $filters = $request->only(['status']);

        $filePath = $this->exportService->exportProjects($organizationId, $filters);
        $fileName = 'projects_export_' . date('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Экспорт материалов
     */
    public function exportMaterials(Request $request): Response
    {
        $organizationId = Auth::user()->current_organization_id;
        $filters = $request->only(['category']);

        $filePath = $this->exportService->exportMaterials($organizationId, $filters);
        $fileName = 'materials_export_' . date('Y-m-d') . '.xlsx';

        return response()->download($filePath, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Определить причину требования внимания к контракту
     */
    private function getAttentionReason(Contract $contract, float $completionPercentage): array
    {
        $reasons = [];

        if ($completionPercentage >= 90.0) {
            $reasons[] = "Приближение к лимиту ({$completionPercentage}%)";
        }

        if ($completionPercentage >= 100) {
            $reasons[] = "Работы выполнены на 100%";
        }

        if ($contract->end_date && $contract->end_date->isPast() && $contract->status === ContractStatusEnum::ACTIVE) {
            $reasons[] = "Превышен срок окончания";
        }

        return $reasons;
    }

    /**
     * Определить приоритет контракта для сортировки
     */
    private function getContractPriority(Contract $contract, float $completionPercentage): int
    {
        $priority = 0;

        // Превышен срок - высший приоритет
        if ($contract->end_date && $contract->end_date->isPast()) {
            $priority += 100;
        }

        // 100% выполнение - высокий приоритет  
        if ($completionPercentage >= 100) {
            $priority += 50;
        }

        // Приближение к лимиту - средний приоритет
        if ($completionPercentage >= 90.0) {
            $priority += 25;
        }

        // Добавляем процент выполнения для точной сортировки
        $priority += $completionPercentage;

        return $priority;
    }
}