<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Services\FilterScopeManager;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class HoldingContractsController extends Controller
{
    public function __construct(
        private FilterScopeManager $filterManager
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $filters = (array) $request->get('filters', []);
            $perPage = (int) $request->get('per_page', 50);

            $query = Contract::query();
            $this->filterManager->applyHoldingFilters($query, $orgId, $filters);
            $query->with(['organization:id,name,is_holding']);

            $contracts = $query->orderByDesc('date')->paginate($perPage);

            return AdminResponse::paginated(
                $contracts->items(),
                [
                    'current_page' => $contracts->currentPage(),
                    'from' => $contracts->firstItem(),
                    'last_page' => $contracts->lastPage(),
                    'path' => $contracts->path(),
                    'per_page' => $contracts->perPage(),
                    'to' => $contracts->lastItem(),
                    'total' => $contracts->total(),
                ],
                null,
                Response::HTTP_OK,
                null,
                [
                    'first' => $contracts->url(1),
                    'last' => $contracts->url($contracts->lastPage()),
                    'prev' => $contracts->previousPageUrl(),
                    'next' => $contracts->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('[HoldingContractsController.index] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'filters' => $request->get('filters', []),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.contracts_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(Request $request, int $contractId): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $childOrgs = Organization::where('parent_organization_id', $orgId)
                ->pluck('id')
                ->toArray();
            $allowedOrgIds = array_merge([$orgId], $childOrgs);

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
                'agreements',
            ])
                ->whereIn('organization_id', $allowedOrgIds)
                ->find($contractId);

            if (!$contract) {
                return AdminResponse::error(trans_message('holding.contract_not_found'), Response::HTTP_NOT_FOUND);
            }

            $totalPaid = $contract->payments->sum('amount');
            $totalActsAmount = $contract->performanceActs->where('status', 'approved')->sum('amount');
            $totalWorksAmount = $contract->completedWorks->where('status', 'approved')->sum('total_amount');
            $remainingAmount = $contract->total_amount - $totalPaid;
            $completionPercentage = $contract->total_amount > 0
                ? round(($totalPaid / $contract->total_amount) * 100, 2)
                : 0;

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

            $childContractsSummary = [
                'total_count' => $contract->childContracts->count(),
                'total_amount' => (float) $contract->childContracts->sum('total_amount'),
                'by_status' => $contract->childContracts->groupBy('status')->map(function ($group) {
                    return [
                        'count' => $group->count(),
                        'total_amount' => (float) $group->sum('total_amount'),
                    ];
                }),
            ];

            $paymentsByType = $contract->payments->groupBy('payment_type')->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => (float) $group->sum('amount'),
                ];
            });

            return AdminResponse::success([
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
            ]);
        } catch (\Throwable $e) {
            Log::error('[HoldingContractsController.show] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'contract_id' => $contractId,
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.contract_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
