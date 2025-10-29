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

    public function index(Request $request, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
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
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specifications = $contractModel->specifications()->get();

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

    public function store(StoreContractSpecificationRequest $request, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
            if (!$contractExists) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден в системе',
                    'debug' => [
                        'contract_id' => $contract,
                        'contract_id_type' => gettype($contract)
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, не удален ли контракт
            if ($contractExists->trashed()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт был удален',
                    'debug' => [
                        'contract_id' => $contract,
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
                        'contract_id' => $contract,
                        'contract_project_id' => $contractExists->project_id,
                        'requested_project_id' => $projectId
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Теперь проверяем доступ через сервис
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contractModel) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту',
                    'debug' => [
                        'contract_id' => $contract,
                        'contract_organization_id' => $contractExists->organization_id ?? null,
                        'your_organization_id' => $organizationId
                    ]
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationDTO = $request->toDto();
            $specification = $this->specificationService->create($specificationDTO);

            $contractModel->specifications()->attach($specification->id, [
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

    public function attach(AttachSpecificationRequest $request, int $contract): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
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
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            $specificationId = $request->input('specification_id');

            if ($contractModel->specifications()->where('specification_id', $specificationId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация уже привязана к контракту'
                ], Response::HTTP_CONFLICT);
            }

            $contractModel->specifications()->attach($specificationId, [
                'attached_at' => now()
            ]);

            $specification = $contractModel->specifications()->find($specificationId);

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

    public function destroy(Request $request, int $contract, int $specification): JsonResponse
    {
        $organizationId = $request->user()?->current_organization_id;
        $projectId = $request->route('project');
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            // Проверяем существование контракта (включая soft-deleted)
            $contractExists = \App\Models\Contract::withTrashed()->find($contract);
            
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
            $contractModel = $this->contractService->getContractById($contract, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Нет доступа к контракту'
                ], Response::HTTP_FORBIDDEN);
            }

            if (!$contractModel->specifications()->where('specification_id', $specification)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Спецификация не привязана к контракту'
                ], Response::HTTP_NOT_FOUND);
            }

            $contractModel->specifications()->detach($specification);

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
