<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use App\Services\Contract\SpecificationService;
use App\Http\Requests\Api\V1\Admin\Contract\Specification\StoreContractSpecificationRequest;
use App\Http\Requests\Api\V1\Admin\Contract\Specification\AttachSpecificationRequest;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Exception;

class ContractSpecificationController extends Controller
{
    protected ContractService $contractService;
    protected SpecificationService $specificationService;

    public function __construct(
        ContractService $contractService,
        SpecificationService $specificationService
    ) {
        $this->contractService = $contractService;
        $this->specificationService = $specificationService;
    }
    
    /**
     * Проверить, принадлежит ли контракт указанному проекту из URL
     */
    private function validateProjectContext(Request $request, $contract): bool
    {
        $projectId = $request->route('project');
        if ($projectId && (int)$contract->project_id !== (int)$projectId) {
            return false;
        }
        return true;
    }

    public function index(Request $request, int $contractId): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contractId);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specifications = $contract->specifications()->get();

            return response()->json([
                'success' => true,
                'data' => SpecificationResource::collection($specifications)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при получении спецификаций',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function store(StoreContractSpecificationRequest $request, int $contractId): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contractId);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе',
                    'debug' => [
                        'contract_id' => $contractId,
                        'contract_id_type' => gettype($contractId)
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален',
                    'debug' => [
                        'contract_id' => $contractId,
                        'deleted_at' => $contractExists->deleted_at
                    ]
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту ДО проверки организации
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту',
                    'debug' => [
                        'contract_id' => $contractId,
                        'contract_project_id' => $contractExists->project_id,
                        'requested_project_id' => $projectId
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Теперь проверяем доступ через сервис
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту',
                    'debug' => [
                        'contract_id' => $contractId,
                        'contract_organization_id' => $contractExists->organization_id,
                        'your_organization_id' => $organizationId
                    ]
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationDTO = $request->toDto();
            $specification = $this->specificationService->create($specificationDTO);

            $contract->specifications()->attach($specification->id, [
                'attached_at' => now()
            ]);

            $specification->load(['contracts']);

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно создана и привязана к контракту',
                'data' => new SpecificationResource($specification)
            ], Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при создании спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function attach(AttachSpecificationRequest $request, int $contractId): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contractId);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationId = $request->input('specification_id');

            if ($contract->specifications()->where('specification_id', $specificationId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация уже привязана к контракту'
                ], Response::HTTP_CONFLICT);
            }

            $contract->specifications()->attach($specificationId, [
                'attached_at' => now()
            ]);

            $specification = $contract->specifications()->find($specificationId);

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно привязана к контракту',
                'data' => new SpecificationResource($specification)
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при привязке спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, int $contractId, int $specificationId): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contractId);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален'
                ], Response::HTTP_GONE);
            }
            
            // Проверяем принадлежность проекту
            if ($projectId && (int)$contractExists->project_id !== (int)$projectId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт принадлежит другому проекту'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем доступ к контракту
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            if (!$contract->specifications()->where('specification_id', $specificationId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация не привязана к контракту'
                ], Response::HTTP_NOT_FOUND);
            }

            $contract->specifications()->detach($specificationId);

            return response()->json([
                'success' => true,
                'message' => 'Спецификация успешно отвязана от контракта'
            ], Response::HTTP_OK);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при отвязке спецификации',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
}
