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
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, что контракт принадлежит указанному проекту
            if (!$this->validateProjectContext($request, $contract)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не принадлежит указанному проекту'
                ], Response::HTTP_NOT_FOUND);
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
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, что контракт принадлежит указанному проекту
            if (!$this->validateProjectContext($request, $contract)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не принадлежит указанному проекту'
                ], Response::HTTP_NOT_FOUND);
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
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, что контракт принадлежит указанному проекту
            if (!$this->validateProjectContext($request, $contract)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не принадлежит указанному проекту'
                ], Response::HTTP_NOT_FOUND);
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
        
        if (!$organizationId) {
            return response()->json(['message' => 'Не определён контекст организации'], 400);
        }

        try {
            $contract = $this->contractService->getContractById($contractId, $organizationId);
            
            if (!$contract) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не найден'
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Проверяем, что контракт принадлежит указанному проекту
            if (!$this->validateProjectContext($request, $contract)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Контракт не принадлежит указанному проекту'
                ], Response::HTTP_NOT_FOUND);
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
