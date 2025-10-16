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
        // Middleware для авторизации можно добавить здесь, например, для всего ресурса:
        // $this->authorizeResource(ContractPayment::class, 'payment');
    }

    public function index(Request $request, int $contractId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->user()?->current_organization_id;

        try {
            // Вместо int $contractId можно использовать Route Model Binding: Contract $contract
            // Тогда проверка на организацию будет: if ($contract->organization_id !== $organizationId) throw ...
            $payments = $this->paymentService->getAllPaymentsForContract($contractId, $organizationId);
            return new ContractPaymentCollection($payments);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve payments', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function store(StoreContractPaymentRequest $request, int $contractId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->user()?->current_organization_id;

        try {
            // Contract $contract (Route Model Binding)
            $paymentDTO = $request->toDto();
            $payment = $this->paymentService->createPaymentForContract($contractId, $organizationId, $paymentDTO);
            return (new ContractPaymentResource($payment))
                    ->response()
                    ->setStatusCode(Response::HTTP_CREATED);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to create payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function show(Request $request, int $paymentId)
    {
        $organizationId = $request->user()?->current_organization_id;

        try {
            $payment = $this->paymentService->getPaymentById($paymentId, null, $organizationId);
            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], Response::HTTP_NOT_FOUND);
            }
            return new ContractPaymentResource($payment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function update(UpdateContractPaymentRequest $request, int $paymentId)
    {
        $organizationId = $request->user()?->current_organization_id;
        
        try {
            $paymentModel = $this->paymentService->getPaymentById($paymentId, null, $organizationId);
            if (!$paymentModel) {
                 return response()->json(['message' => 'Payment not found'], Response::HTTP_NOT_FOUND);
            }

            $paymentDTO = $request->toDto();
            $updatedPayment = $this->paymentService->updatePayment($paymentId, null, $organizationId, $paymentDTO);
            return new ContractPaymentResource($updatedPayment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, int $paymentId)
    {
        $organizationId = $request->user()?->current_organization_id;

        try {
            $this->paymentService->deletePayment($paymentId, null, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
} 