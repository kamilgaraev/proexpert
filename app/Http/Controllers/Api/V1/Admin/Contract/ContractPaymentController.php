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
        $organizationId = $request->input('organization_id', 1); // Временно

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
        $organizationId = $request->input('organization_id_for_creation', 1); // Временно

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

    public function show(Request $request, int $contractId, int $paymentId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_show', 1); // Временно

        try {
            // Contract $contract, ContractPayment $payment (Route Model Binding)
            $payment = $this->paymentService->getPaymentById($paymentId, $contractId, $organizationId);
            if (!$payment) {
                return response()->json(['message' => 'Payment not found'], Response::HTTP_NOT_FOUND);
            }
            return new ContractPaymentResource($payment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to retrieve payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function update(UpdateContractPaymentRequest $request, int $contractId, int $paymentId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_update', 1); // Временно
        
        try {
            // Contract $contract, ContractPayment $payment (Route Model Binding)
            // Передача $payment модели в toDto() из UpdateContractPaymentRequest теперь важна.
            // Чтобы это работало, параметр в методе контроллера должен называться 'payment', как в маршруте
            $paymentModel = $this->paymentService->getPaymentById($paymentId, $contractId, $organizationId); // Получаем модель для передачи в DTO
            if (!$paymentModel) {
                 return response()->json(['message' => 'Payment not found'], Response::HTTP_NOT_FOUND);
            }
            // Заменяем $this->route('payment') в UpdateContractPaymentRequest на явную передачу модели
            // или модифицируем UpdateContractPaymentRequest для работы с $this->route('payment')
            // Для простоты, предположим UpdateContractPaymentRequest настроен на $this->route('payment')

            $paymentDTO = $request->toDto(); // $request->route('payment') будет использован в toDto()
            $updatedPayment = $this->paymentService->updatePayment($paymentId, $contractId, $organizationId, $paymentDTO);
            return new ContractPaymentResource($updatedPayment);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to update payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    public function destroy(Request $request, int $contractId, int $paymentId)
    {
        // $organizationId = Auth::user()->organization_id;
        $organizationId = $request->input('organization_id_for_destroy', 1); // Временно

        try {
            // Contract $contract, ContractPayment $payment (Route Model Binding)
            $this->paymentService->deletePayment($paymentId, $contractId, $organizationId);
            return response()->json(null, Response::HTTP_NO_CONTENT);
        } catch (Exception $e) {
            return response()->json(['message' => 'Failed to delete payment', 'error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
} 