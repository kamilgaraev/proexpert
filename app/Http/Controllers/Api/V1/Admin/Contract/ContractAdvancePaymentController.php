<?php

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractAdvancePaymentService;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractAdvancePaymentRequest;
use App\Models\ContractAdvancePayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContractAdvancePaymentController extends Controller
{
    public function __construct(
        private ContractAdvancePaymentService $service
    ) {}

    public function index(Request $request, int $contractId)
    {
        $advancePayments = $this->service->getByContract($contractId);

        return response()->json([
            'success' => true,
            'data' => $advancePayments
        ]);
    }

    public function store(StoreContractAdvancePaymentRequest $request, int $contractId)
    {
        $dto = $request->toDto();
        $advancePayment = $this->service->create($contractId, $dto);

        return response()->json([
            'success' => true,
            'message' => 'Авансовый платеж успешно создан',
            'data' => $advancePayment
        ], Response::HTTP_CREATED);
    }

    public function show(int $contractId, int $advancePaymentId)
    {
        $advancePayment = ContractAdvancePayment::where('contract_id', $contractId)
            ->findOrFail($advancePaymentId);

        return response()->json([
            'success' => true,
            'data' => $advancePayment
        ]);
    }

    public function update(StoreContractAdvancePaymentRequest $request, int $contractId, int $advancePaymentId)
    {
        $dto = $request->toDto();
        $advancePayment = $this->service->update($advancePaymentId, $dto);

        return response()->json([
            'success' => true,
            'message' => 'Авансовый платеж успешно обновлен',
            'data' => $advancePayment
        ]);
    }

    public function destroy(int $contractId, int $advancePaymentId)
    {
        $this->service->delete($advancePaymentId);

        return response()->json([
            'success' => true,
            'message' => 'Авансовый платеж успешно удален'
        ], Response::HTTP_NO_CONTENT);
    }
}
