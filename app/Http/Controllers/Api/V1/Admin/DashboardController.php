<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Services\Admin\DashboardService;
use Illuminate\Http\Request;
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

    public function __construct(DashboardService $dashboardService)
    {
        $this->dashboardService = $dashboardService;
        $this->middleware('can:access-admin-panel');
    }

    /**
     * Получить сводную информацию для дашборда админки
     */
    public function index(Request $request): JsonResponse
    {
        $summary = $this->dashboardService->getSummary($request);
        return response()->json(['success' => true, 'data' => $summary]);
    }

    /**
     * Временной ряд по выбранной метрике
     */
    public function timeseries(Request $request): JsonResponse
    {
        $metric = $request->input('metric', 'users');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getTimeseries($metric, $period, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Топ-5 сущностей по активности/объёму
     */
    public function topEntities(Request $request): JsonResponse
    {
        $entity = $request->input('entity', 'projects');
        $period = $request->input('period', 'month');
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getTopEntities($entity, $period, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * История последних действий/операций
     */
    public function history(Request $request): JsonResponse
    {
        $type = $request->input('type', 'materials');
        $limit = (int)$request->input('limit', 10);
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getHistory($type, $limit, $organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Лимиты и их заполнение
     */
    public function limits(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');
        $data = $this->dashboardService->getLimits($organizationId);
        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Получить контракты требующие внимания
     */
    public function contractsRequiringAttention(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contracts = Contract::where('organization_id', $organizationId)
            ->with(['project:id,name', 'contractor:id,name'])
            ->get()
            ->filter(function ($contract) {
                return $contract->isNearingLimit() || 
                       $contract->completion_percentage >= 100 ||
                       ($contract->end_date && $contract->end_date->isPast() && $contract->status === ContractStatusEnum::ACTIVE);
            })
            ->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'project_name' => $contract->project?->name,
                    'contractor_name' => $contract->contractor?->name,
                    'total_amount' => (float) $contract->total_amount,
                    'completed_works_amount' => $contract->completed_works_amount,
                    'completion_percentage' => $contract->completion_percentage,
                    'remaining_amount' => $contract->remaining_amount,
                    'status' => $contract->status->value,
                    'end_date' => $contract->end_date?->format('Y-m-d'),
                    'is_nearing_limit' => $contract->isNearingLimit(),
                    'is_overdue' => $contract->end_date && $contract->end_date->isPast(),
                    'is_completed' => $contract->completion_percentage >= 100,
                    'attention_reason' => $this->getAttentionReason($contract),
                    'priority' => $this->getContractPriority($contract),
                ];
            })
            ->sortByDesc('priority')
            ->values();

        return response()->json([
            'success' => true,
            'data' => $contracts
        ]);
    }

    /**
     * Получить общую статистику по контрактам
     */
    public function contractsStatistics(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $stats = DB::table('contracts')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total_contracts,
                COUNT(CASE WHEN status = ? THEN 1 END) as active_contracts,
                COUNT(CASE WHEN status = ? THEN 1 END) as completed_contracts,
                COUNT(CASE WHEN status = ? THEN 1 END) as draft_contracts,
                SUM(total_amount) as total_amount,
                AVG(total_amount) as avg_amount
            ', [
                ContractStatusEnum::ACTIVE->value,
                ContractStatusEnum::COMPLETED->value,
                ContractStatusEnum::DRAFT->value
            ])
            ->first();

        // Статистика по выполненным работам
        $worksStats = DB::table('completed_works')
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total_works,
                COUNT(CASE WHEN status = ? THEN 1 END) as confirmed_works,
                SUM(CASE WHEN status = ? THEN total_amount ELSE 0 END) as confirmed_amount
            ', ['confirmed', 'confirmed'])
            ->first();

        // Контракты требующие внимания
        $contractsNeedingAttention = Contract::where('organization_id', $organizationId)
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
     * Получить топ контрактов по объему
     */
    public function topContracts(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $limit = $request->query('limit', 5);

        $contracts = Contract::where('organization_id', $organizationId)
            ->with(['project:id,name', 'contractor:id,name'])
            ->orderBy('total_amount', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($contract) {
                return [
                    'id' => $contract->id,
                    'number' => $contract->number,
                    'project_name' => $contract->project?->name,
                    'contractor_name' => $contract->contractor?->name,
                    'total_amount' => (float) $contract->total_amount,
                    'completed_works_amount' => $contract->completed_works_amount,
                    'completion_percentage' => $contract->completion_percentage,
                    'status' => $contract->status->value,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $contracts
        ]);
    }

    /**
     * Получить активность по выполненным работам (последние 30 дней)
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        $days = $request->query('days', 30);

        $activity = CompletedWork::where('organization_id', $organizationId)
            ->where('created_at', '>=', Carbon::now()->subDays($days))
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
     * Определить причину требования внимания к контракту
     */
    private function getAttentionReason(Contract $contract): array
    {
        $reasons = [];

        if ($contract->isNearingLimit()) {
            $reasons[] = "Приближение к лимиту ({$contract->completion_percentage}%)";
        }

        if ($contract->completion_percentage >= 100) {
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
    private function getContractPriority(Contract $contract): int
    {
        $priority = 0;

        // Превышен срок - высший приоритет
        if ($contract->end_date && $contract->end_date->isPast()) {
            $priority += 100;
        }

        // 100% выполнение - высокий приоритет  
        if ($contract->completion_percentage >= 100) {
            $priority += 50;
        }

        // Приближение к лимиту - средний приоритет
        if ($contract->isNearingLimit()) {
            $priority += 25;
        }

        // Добавляем процент выполнения для точной сортировки
        $priority += $contract->completion_percentage;

        return $priority;
    }
} 