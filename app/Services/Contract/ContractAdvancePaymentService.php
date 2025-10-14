<?php

namespace App\Services\Contract;

use App\Models\ContractAdvancePayment;
use App\DTOs\ContractAdvancePaymentDTO;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class ContractAdvancePaymentService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    public function create(int $contractId, ContractAdvancePaymentDTO $dto): ContractAdvancePayment
    {
        try {
            DB::beginTransaction();

            $advancePayment = ContractAdvancePayment::create([
                'contract_id' => $dto->contract_id,
                'amount' => $dto->amount,
                'description' => $dto->description,
                'payment_date' => $dto->payment_date,
            ]);

            DB::commit();

            $this->logging->business('contract.advance_payment.created', [
                'contract_id' => $contractId,
                'advance_payment_id' => $advancePayment->id,
                'amount' => $dto->amount,
            ]);

            return $advancePayment;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to create contract advance payment', [
                'contract_id' => $contractId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function update(int $advancePaymentId, ContractAdvancePaymentDTO $dto): ContractAdvancePayment
    {
        try {
            DB::beginTransaction();

            $advancePayment = ContractAdvancePayment::findOrFail($advancePaymentId);
            $advancePayment->update([
                'amount' => $dto->amount,
                'description' => $dto->description,
                'payment_date' => $dto->payment_date,
            ]);

            DB::commit();

            $this->logging->business('contract.advance_payment.updated', [
                'advance_payment_id' => $advancePaymentId,
                'amount' => $dto->amount,
            ]);

            return $advancePayment;
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to update contract advance payment', [
                'advance_payment_id' => $advancePaymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function delete(int $advancePaymentId): void
    {
        try {
            DB::beginTransaction();

            $advancePayment = ContractAdvancePayment::findOrFail($advancePaymentId);
            $advancePayment->delete();

            DB::commit();

            $this->logging->business('contract.advance_payment.deleted', [
                'advance_payment_id' => $advancePaymentId,
            ]);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete contract advance payment', [
                'advance_payment_id' => $advancePaymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function getByContract(int $contractId): array
    {
        return ContractAdvancePayment::where('contract_id', $contractId)
            ->orderBy('payment_date')
            ->get()
            ->toArray();
    }
}
