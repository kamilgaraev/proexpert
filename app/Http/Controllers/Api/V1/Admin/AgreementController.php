<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Contract\SupplementaryAgreementService;
use App\Http\Requests\Api\V1\Admin\Agreement\StoreSupplementaryAgreementRequest;
use App\Http\Requests\Api\V1\Admin\Agreement\UpdateSupplementaryAgreementRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Exception;

class AgreementController extends Controller
{
    public function __construct(private SupplementaryAgreementService $service) {}
    
    /**
     * Проверить, принадлежит ли agreement контракту, который принадлежит указанному проекту и организации
     */
    private function validateAgreementAccess(Request $request, $agreement): array
    {
        $projectId = $request->route('project');
        $organizationId = $request->user()?->current_organization_id;
        
        if (!$agreement) {
            return ['valid' => false, 'message' => 'Дополнительное соглашение не найдено'];
        }
        
        // Загружаем контракт, если еще не загружен
        if (!$agreement->relationLoaded('contract')) {
            $agreement->load('contract');
        }
        
        $contract = $agreement->contract;
        if (!$contract) {
            return ['valid' => false, 'message' => 'Контракт не найден'];
        }
        
        // Проверяем доступ к организации
        if ($contract->organization_id !== $organizationId) {
            return ['valid' => false, 'message' => 'Нет доступа к этому соглашению'];
        }
        
        // Проверяем project context
        if ($projectId && (int)$contract->project_id !== (int)$projectId) {
            return ['valid' => false, 'message' => 'Соглашение не принадлежит указанному проекту'];
        }
        
        return ['valid' => true];
    }

    public function index(Request $request, int $project = null)
    {
        // Получаем project_id из URL (обязательный параметр для project-based маршрутов)
        $projectId = $project;
        $perPage = $request->query('per_page', 15);
        
        // TODO: Добавить фильтрацию по project_id в SupplementaryAgreementService
        // Пока возвращаем все для проекта
        return $this->service->paginate($perPage);
    }

    public function store(StoreSupplementaryAgreementRequest $request, int $project)
    {
        $agreement = $this->service->create($request->toDto());
        return response()->json($agreement, Response::HTTP_CREATED);
    }

    public function show(Request $request, int $project, int $agreement)
    {
        $agreementModel = $this->service->getById($agreement);
        
        $validation = $this->validateAgreementAccess($request, $agreementModel);
        if (!$validation['valid']) {
            return response()->json(['message' => $validation['message']], Response::HTTP_NOT_FOUND);
        }
        
        return $agreementModel;
    }

    public function update(UpdateSupplementaryAgreementRequest $request, int $project, int $agreement)
    {
        $agreementModel = $this->service->getById($agreement);
        
        $validation = $this->validateAgreementAccess($request, $agreementModel);
        if (!$validation['valid']) {
            return response()->json(['message' => $validation['message']], Response::HTTP_NOT_FOUND);
        }
        
        $dto = $request->toDto($agreementModel->contract_id);
        $this->service->update($agreement, $dto);
        return response()->json($this->service->getById($agreement));
    }

    public function destroy(Request $request, int $project, int $agreement)
    {
        $agreementModel = $this->service->getById($agreement);
        
        $validation = $this->validateAgreementAccess($request, $agreementModel);
        if (!$validation['valid']) {
            return response()->json(['message' => $validation['message']], Response::HTTP_NOT_FOUND);
        }
        
        $this->service->delete($agreement);
        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function applyChanges(Request $request, int $project, int $agreement)
    {
        $agreementModel = $this->service->getById($agreement);
        
        $validation = $this->validateAgreementAccess($request, $agreementModel);
        if (!$validation['valid']) {
            return response()->json([
                'success' => false,
                'message' => $validation['message']
            ], Response::HTTP_NOT_FOUND);
        }
        
        try {
            $this->service->applyChangesToContract($agreement);
            return response()->json([
                'success' => true,
                'message' => 'Изменения дополнительного соглашения успешно применены к контракту'
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка при применении изменений',
                'error' => $e->getMessage()
            ], Response::HTTP_BAD_REQUEST);
        }
    }
} 