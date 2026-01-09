<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractPaymentService;
use App\Http\Requests\Api\V1\Admin\Contract\Payment\StoreContractPaymentRequest;
use App\Http\Requests\Api\V1\Admin\Contract\Payment\UpdateContractPaymentRequest;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentResource;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentCollection;
use App\Models\Contract; // Для Route Model Binding
use App\Models\ContractPayment; // Для Route Model Binding
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Exception;

class ContractPaymentController extends Controller
{
    protected ContractPaymentService $paymentService;

    public function __construct(ContractPaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }
    
    private function validateProjectContext(Request $request, $payment): bool
    {
        $projectId = $request->route('project');
        
        if (!$projectId || !$payment->contract) {
            return true;
        }

        if ($payment->contract->is_multi_project) {
            // Для мультипроектных контрактов проверяем связь через отношение projects
            return $payment->contract->projects()->where('projects.id', $projectId)->exists();
        }

        if ((int)$payment->contract->project_id !== (int)$projectId) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Проверяет, имеет ли организация доступ к контракту
     * Организация может быть либо заказчиком, либо подрядчиком
     */
    private function canAccessContract(Contract $contract, int $organizationId): bool
    {
        // Проверяем, является ли организация заказчиком
        if ($contract->organization_id === $organizationId) {
            return true;
        }
        
        // Проверяем, является ли организация подрядчиком
        if ($contract->contractor_id) {
            // Загружаем подрядчика, если еще не загружен
            if (!$contract->relationLoaded('contractor')) {
                $contract->load('contractor');
            }
            
            if ($contract->contractor) {
                // Подрядчик может принадлежать организации напрямую или через source_organization_id
                return $contract->contractor->organization_id === $organizationId 
                    || $contract->contractor->source_organization_id === $organizationId;
            }
        }
        
        return false;
    }

    public function index(Request $request, int $project, int $contract)
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? ($request->attributes->get('current_organization_id') ?? $user->current_organization_id);
        $projectId = $project;

        if (!$organizationId) {
            return AdminResponse::error(__('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST, 'MISSING_ORGANIZATION_CONTEXT');
        }

        try {
            $payments = $this->paymentService->getAllPaymentsForContract($contract, $organizationId, [], $projectId);
            return new ContractPaymentCollection($payments);
        } catch (Exception $e) {
            return AdminResponse::error(__('contract.payment_retrieve_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function store(StoreContractPaymentRequest $request, int $project, int $contract)
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? ($request->attributes->get('current_organization_id') ?? $user->current_organization_id);
        $projectId = $project;

        if (!$organizationId) {
            return AdminResponse::error(__('contract.organization_context_missing'), Response::HTTP_BAD_REQUEST, 'MISSING_ORGANIZATION_CONTEXT');
        }

        try {
            $paymentDTO = $request->toDto();
            $payment = $this->paymentService->createPaymentForContract($contract, $organizationId, $paymentDTO, $projectId);
            
            return AdminResponse::success(new ContractPaymentResource($payment), __('contract.payment_created'), Response::HTTP_CREATED);
        } catch (Exception $e) {
            return AdminResponse::error(__('contract.payment_create_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function show(Request $request, int $project, int $contract, ContractPayment $payment)
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? ($request->attributes->get('current_organization_id') ?? $user->current_organization_id);

        try {
            $contractModel = $payment->contract;
            if (!$contractModel || !$this->canAccessContract($contractModel, $organizationId)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            return new ContractPaymentResource($payment);
        } catch (Exception $e) {
            return AdminResponse::error(__('contract.payment_retrieve_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function update(UpdateContractPaymentRequest $request, int $project, int $contract, ContractPayment $payment)
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? ($request->attributes->get('current_organization_id') ?? $user->current_organization_id);
        
        try {
            $contractModel = $payment->contract;
            if (!$contractModel || !$this->canAccessContract($contractModel, $organizationId)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }

            $paymentDTO = $request->toDto();
            $updatedPayment = $this->paymentService->updatePayment($payment->id, null, $organizationId, $paymentDTO);
            return AdminResponse::success(new ContractPaymentResource($updatedPayment), __('contract.payment_updated'));
        } catch (Exception $e) {
            return AdminResponse::error(__('contract.payment_update_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }

    public function destroy(Request $request, int $project, int $contract, ContractPayment $payment)
    {
        $user = $request->user();
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? ($request->attributes->get('current_organization_id') ?? $user->current_organization_id);

        try {
            $contractModel = $payment->contract;
            if (!$contractModel || !$this->canAccessContract($contractModel, $organizationId)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return AdminResponse::error(__('contract.payment_not_found'), Response::HTTP_NOT_FOUND);
            }

            $this->paymentService->deletePayment($payment->id, null, $organizationId);
            return AdminResponse::success(null, __('contract.payment_deleted'), Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return AdminResponse::error(__('contract.payment_delete_error'), Response::HTTP_BAD_REQUEST, $e->getMessage());
        }
    }
} 