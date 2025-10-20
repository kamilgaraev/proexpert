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
        if ($projectId && $payment->contract && (int)$payment->contract->project_id !== (int)$projectId) {
            return false;
        }
        return true;
    }

    public function index(Request $request, int $contractId)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;

        try {
            $payments = $this->paymentService->getAllPaymentsForContract($contractId, $organizationId);
            return new ContractPaymentCollection($payments);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve payments', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function store(StoreContractPaymentRequest $request, int $contractId)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;

        try {
            $paymentDTO = $request->toDto();
            $payment = $this->paymentService->createPaymentForContract($contractId, $organizationId, $paymentDTO);
            return (new ContractPaymentResource($payment))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(Request $request, ContractPayment $payment)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;

        try {
            $contract = $payment->contract;
            if (!$contract || $contract->organization_id !== $organizationId) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }
            
            return new ContractPaymentResource($payment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function update(UpdateContractPaymentRequest $request, ContractPayment $payment)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;
        
        try {
            $contract = $payment->contract;
            if (!$contract || $contract->organization_id !== $organizationId) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }

            $paymentDTO = $request->toDto();
            $updatedPayment = $this->paymentService->updatePayment($payment->id, null, $organizationId, $paymentDTO);
            return new ContractPaymentResource($updatedPayment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, ContractPayment $payment)
    {
        $organization = $request->attributes->get('current_organization');
        $organizationId = $organization?->id ?? $request->user()?->current_organization_id;

        try {
            $contract = $payment->contract;
            if (!$contract || $contract->organization_id !== $organizationId) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }
            
            if (!$this->validateProjectContext($request, $payment)) {
                return response()->json(['message' => 'Payment not found or access denied'], Response::HTTP_NOT_FOUND);
            }

            $this->paymentService->deletePayment($payment->id, null, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
} 