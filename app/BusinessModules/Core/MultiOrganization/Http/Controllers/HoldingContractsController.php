<?php

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Organization;
use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HoldingContractsController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $orgId = $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return response()->json([
                    'success' => false,
                    'error' => 'Access restricted to holding organizations'
                ], 403);
            }

            $filters = $request->get('filters', []);
            $perPage = $request->get('per_page', 50);

            $query = Contract::query();
            $this->filterManager->applyHoldingFilters($query, $orgId, $filters);

            $query->with(['organization:id,name,is_holding']);

            $contracts = $query->orderBy('date', 'desc')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $contracts,
            ]);
        } catch (\Exception $e) {
            \Log::error('HoldingContractsController::index error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Получить детальную информацию о контракте
     * 
     * GET /api/v1/landing/multi-organization/contracts/{contractId}
     */
    public function show(Request $request, int $contractId): JsonResponse
    {
        $orgId = $request->attributes->get('current_organization_id');
        $org = Organization::findOrFail($orgId);

        if (!$org->is_holding) {
            return response()->json([
                'success' => false,
                'error' => 'Access restricted to holding organizations'
            ], 403);
        }

        // Получить список доступных организаций (холдинг + дочерние)
        $childOrgs = Organization::where('parent_organization_id', $orgId)
            ->pluck('id')
            ->toArray();
        $allowedOrgIds = array_merge([$orgId], $childOrgs);

        // Найти контракт с проверкой доступа
        $contract = Contract::with([
            'organization:id,name,tax_number,phone,email,address,is_holding',
            'project:id,name,address,status,start_date,end_date,budget_amount,organization_id',
            'project.organization:id,name',
            'contractor',
            'parentContract',
            'childContracts',
            'performanceActs',
            'payments',
            'completedWorks',
            'agreements'
        ])
        ->whereIn('organization_id', $allowedOrgIds)
        ->find($contractId);

        if (!$contract) {
            return response()->json([
                'success' => false,
                'error' => 'Contract not found or access denied'
            ], 404);
        }

        // Рассчитать агрегированные данные
        $totalPaid = $contract->payments->sum('amount');
        $totalActsAmount = $contract->performanceActs->where('status', 'approved')->sum('amount');
        $totalWorksAmount = $contract->completedWorks->where('status', 'approved')->sum('total_amount');
        $remainingAmount = $contract->total_amount - $totalPaid;
        $completionPercentage = $contract->total_amount > 0 
            ? round(($totalPaid / $contract->total_amount) * 100, 2)
            : 0;

        // Финансовые показатели
        $financialSummary = [
            'total_amount' => (float) $contract->total_amount,
            'gp_amount' => (float) $contract->gp_amount,
            'subcontract_amount' => (float) $contract->subcontract_amount,
            'planned_advance' => (float) $contract->planned_advance_amount,
            'actual_advance' => (float) $contract->actual_advance_amount,
            'total_paid' => (float) $totalPaid,
            'total_acts_approved' => (float) $totalActsAmount,
            'total_works_approved' => (float) $totalWorksAmount,
            'remaining_amount' => (float) $remainingAmount,
            'completion_percentage' => $completionPercentage,
        ];

        // Статистика по дочерним контрактам
        $childContractsSummary = [
            'total_count' => $contract->childContracts->count(),
            'total_amount' => (float) $contract->childContracts->sum('total_amount'),
            'by_status' => $contract->childContracts->groupBy('status')->map(function($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => (float) $group->sum('total_amount'),
                ];
            }),
        ];

        // Платежи по типам
        $paymentsByType = $contract->payments->groupBy('payment_type')->map(function($group) {
            return [
                'count' => $group->count(),
                'total_amount' => (float) $group->sum('amount'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'contract' => $contract,
                'financial_summary' => $financialSummary,
                'child_contracts_summary' => $childContractsSummary,
                'payments_by_type' => $paymentsByType,
                'timeline' => [
                    'contract_date' => $contract->date,
                    'start_date' => $contract->start_date,
                    'end_date' => $contract->end_date,
                    'days_total' => $contract->start_date && $contract->end_date 
                        ? $contract->start_date->diffInDays($contract->end_date)
                        : null,
                    'days_passed' => $contract->start_date 
                        ? $contract->start_date->diffInDays(now())
                        : null,
                    'days_remaining' => $contract->end_date 
                        ? now()->diffInDays($contract->end_date, false)
                        : null,
                ],
            ],
        ]);
    }
}

