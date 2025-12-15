<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractProjectAllocation;
use App\Services\Contract\ContractAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ContractAllocationController extends Controller
{
    protected ContractAllocationService $allocationService;

    public function __construct(ContractAllocationService $allocationService)
    {
        $this->allocationService = $allocationService;
    }

    /**
     * Получить сводку по распределению контракта
     * 
     * GET /api/v1/admin/contracts/{contractId}/allocations/summary
     */
    public function summary(int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $summary = $this->allocationService->getAllocationSummary($contract);

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Получить список распределений контракта
     * 
     * GET /api/v1/admin/contracts/{contractId}/allocations
     */
    public function index(int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $allocations = $contract->activeAllocations()
            ->with('project')
            ->get()
            ->map(function ($allocation) {
                return [
                    'id' => $allocation->id,
                    'project_id' => $allocation->project_id,
                    'project_name' => $allocation->project->name ?? 'N/A',
                    'allocation_type' => $allocation->allocation_type->value,
                    'allocation_type_label' => $allocation->allocation_type->label(),
                    'allocated_amount' => $allocation->calculateAllocatedAmount(),
                    'allocated_percentage' => $allocation->allocated_percentage,
                    'custom_formula' => $allocation->custom_formula,
                    'notes' => $allocation->notes,
                    'created_at' => $allocation->created_at,
                    'updated_at' => $allocation->updated_at,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $allocations,
        ]);
    }

    /**
     * Создать или обновить распределения контракта
     * 
     * POST /api/v1/admin/contracts/{contractId}/allocations/sync
     */
    public function sync(Request $request, int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'allocations' => ['required', 'array'],
            'allocations.*.project_id' => ['required', 'integer', 'exists:projects,id'],
            'allocations.*.allocation_type' => ['required', 'string', Rule::in(['fixed', 'percentage', 'auto', 'custom'])],
            'allocations.*.allocated_amount' => ['nullable', 'numeric', 'min:0'],
            'allocations.*.allocated_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'allocations.*.custom_formula' => ['nullable', 'array'],
            'allocations.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $allocations = $this->allocationService->syncAllocations($contract, $validated['allocations']);

            return response()->json([
                'success' => true,
                'message' => 'Распределение контракта успешно обновлено',
                'data' => $allocations->map(function ($allocation) {
                    return [
                        'id' => $allocation->id,
                        'project_id' => $allocation->project_id,
                        'allocation_type' => $allocation->allocation_type->value,
                        'allocated_amount' => $allocation->calculateAllocatedAmount(),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при обновлении распределения: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Создать автоматическое равномерное распределение
     * 
     * POST /api/v1/admin/contracts/{contractId}/allocations/auto-equal
     */
    public function createAutoEqual(int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        if (!$contract->is_multi_project) {
            return response()->json([
                'success' => false,
                'message' => 'Автоматическое распределение доступно только для мультипроектных контрактов',
            ], 422);
        }

        try {
            $allocations = $this->allocationService->createAutoEqualDistribution($contract);

            return response()->json([
                'success' => true,
                'message' => 'Создано равномерное распределение',
                'data' => $allocations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании распределения: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Создать распределение на основе актов
     * 
     * POST /api/v1/admin/contracts/{contractId}/allocations/auto-acts
     */
    public function createBasedOnActs(int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        if (!$contract->is_multi_project) {
            return response()->json([
                'success' => false,
                'message' => 'Распределение на основе актов доступно только для мультипроектных контрактов',
            ], 422);
        }

        try {
            $allocations = $this->allocationService->createDistributionBasedOnActs($contract);

            return response()->json([
                'success' => true,
                'message' => 'Создано распределение на основе актов',
                'data' => $allocations,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании распределения: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Конвертировать автоматическое распределение в фиксированное
     * 
     * POST /api/v1/admin/allocations/{allocationId}/convert-to-fixed
     */
    public function convertToFixed(int $allocationId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Проверяем, что allocation принадлежит организации пользователя
        $allocation = ContractProjectAllocation::whereHas('contract', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->findOrFail($allocationId);

        try {
            $updatedAllocation = $this->allocationService->convertAutoToFixed($allocationId);

            return response()->json([
                'success' => true,
                'message' => 'Распределение конвертировано в фиксированное',
                'data' => [
                    'id' => $updatedAllocation->id,
                    'allocation_type' => $updatedAllocation->allocation_type->value,
                    'allocated_amount' => $updatedAllocation->allocated_amount,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при конвертации: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Удалить распределение
     * 
     * DELETE /api/v1/admin/allocations/{allocationId}
     */
    public function destroy(int $allocationId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Проверяем, что allocation принадлежит организации пользователя
        $allocation = ContractProjectAllocation::whereHas('contract', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->findOrFail($allocationId);

        try {
            $this->allocationService->deleteAllocation($allocationId);

            return response()->json([
                'success' => true,
                'message' => 'Распределение успешно удалено',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при удалении: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Получить историю изменений распределения
     * 
     * GET /api/v1/admin/allocations/{allocationId}/history
     */
    public function history(int $allocationId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        // Проверяем, что allocation принадлежит организации пользователя
        $allocation = ContractProjectAllocation::whereHas('contract', function ($q) use ($organizationId) {
            $q->where('organization_id', $organizationId);
        })->findOrFail($allocationId);

        $history = $this->allocationService->getAllocationHistory($allocationId);

        return response()->json([
            'success' => true,
            'data' => $history->map(function ($item) {
                return [
                    'id' => $item->id,
                    'action' => $item->action,
                    'old_values' => $item->old_values,
                    'new_values' => $item->new_values,
                    'reason' => $item->reason,
                    'user' => [
                        'id' => $item->user->id ?? null,
                        'name' => $item->user->name ?? 'N/A',
                    ],
                    'ip_address' => $item->ip_address,
                    'created_at' => $item->created_at,
                ];
            }),
        ]);
    }

    /**
     * Пересчитать автоматические распределения
     * 
     * POST /api/v1/admin/contracts/{contractId}/allocations/recalculate
     */
    public function recalculate(int $contractId): JsonResponse
    {
        $organizationId = Auth::user()->current_organization_id;
        
        $contract = Contract::where('id', $contractId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        try {
            $allocations = $this->allocationService->recalculateAutoAllocations($contract);

            return response()->json([
                'success' => true,
                'message' => 'Автоматические распределения пересчитаны',
                'data' => $allocations->map(function ($allocation) {
                    return [
                        'id' => $allocation->id,
                        'project_id' => $allocation->project_id,
                        'allocated_amount' => $allocation->calculateAllocatedAmount(),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при пересчете: ' . $e->getMessage(),
            ], 500);
        }
    }
}

