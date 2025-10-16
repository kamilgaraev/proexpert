<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Models\ContractPayment;
use App\Models\Contract;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Illuminate\Support\Collection;
use Exception;

class ContractPaymentService
{
    protected ContractPaymentRepositoryInterface $paymentRepository;
    protected ContractRepositoryInterface $contractRepository;

    public function __construct(
        ContractPaymentRepositoryInterface $paymentRepository,
        ContractRepositoryInterface $contractRepository
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->contractRepository = $contractRepository;
    }

    protected function getContractOrFail(int $contractId, int $organizationId): Contract
    {
        $contract = $this->contractRepository->find($contractId);
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Contract not found or does not belong to the organization.');
        }
        return $contract;
    }

    /**
     * Пересчитать и обновить actual_advance_amount в контракте
     */
    protected function updateActualAdvanceAmount(int $contractId): void
    {
        $contract = $this->contractRepository->find($contractId);
        if (!$contract) {
            return;
        }

        // Сумма всех авансовых платежей
        $totalAdvanceAmount = $this->paymentRepository->getAdvancePaymentsSum($contractId);
        
        // Обновляем поле actual_advance_amount
        $this->contractRepository->update($contractId, [
            'actual_advance_amount' => $totalAdvanceAmount
        ]);
    }

    public function getAllPaymentsForContract(int $contractId, int $organizationId, array $filters = []): Collection
    {
        $this->getContractOrFail($contractId, $organizationId); 
        return $this->paymentRepository->getPaymentsForContract($contractId, $filters);
    }

    public function createPaymentForContract(int $contractId, int $organizationId, ContractPaymentDTO $paymentDTO): ContractPayment
    {
        $contract = $this->getContractOrFail($contractId, $organizationId);
        
        $paymentData = $paymentDTO->toArray();
        $paymentData['contract_id'] = $contract->id;

        $payment = $this->paymentRepository->create($paymentData);

        // Если это авансовый платеж - обновляем actual_advance_amount
        if ($paymentDTO->payment_type === ContractPaymentTypeEnum::ADVANCE) {
            $this->updateActualAdvanceAmount($contractId);
        }

        return $payment;
    }

    public function getPaymentById(int $paymentId, ?int $contractId, int $organizationId): ?ContractPayment
    {
        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment) {
            return null;
        }

        $contract = $this->contractRepository->find($payment->contract_id);
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Payment not found or does not belong to the organization.');
        }

        if ($contractId !== null && $payment->contract_id !== $contractId) {
            return null;
        }

        return $payment;
    }

    public function updatePayment(int $paymentId, ?int $contractId, int $organizationId, ContractPaymentDTO $paymentDTO): ContractPayment
    {
        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment) {
            throw new Exception('Payment not found.');
        }

        $contract = $this->contractRepository->find($payment->contract_id);
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Payment not found or does not belong to the organization.');
        }

        if ($contractId !== null && $payment->contract_id !== $contractId) {
            throw new Exception('Payment not found or does not belong to the specified contract.');
        }

        $oldPaymentType = $payment->payment_type;
        $updateData = $paymentDTO->toArray();
        $updated = $this->paymentRepository->update($paymentId, $updateData);

        if (!$updated) {
            throw new Exception('Failed to update payment.');
        }

        if ($oldPaymentType === ContractPaymentTypeEnum::ADVANCE || $paymentDTO->payment_type === ContractPaymentTypeEnum::ADVANCE) {
            $this->updateActualAdvanceAmount($payment->contract_id);
        }

        return $this->paymentRepository->find($paymentId); 
    }

    public function deletePayment(int $paymentId, ?int $contractId, int $organizationId): bool
    {
        $payment = $this->paymentRepository->find($paymentId);
        if (!$payment) {
            throw new Exception('Payment not found.');
        }

        $contract = $this->contractRepository->find($payment->contract_id);
        if (!$contract || $contract->organization_id !== $organizationId) {
            throw new Exception('Payment not found or does not belong to the organization.');
        }

        if ($contractId !== null && $payment->contract_id !== $contractId) {
            throw new Exception('Payment not found or does not belong to the specified contract.');
        }

        $wasAdvancePayment = $payment->payment_type === ContractPaymentTypeEnum::ADVANCE;
        $actualContractId = $payment->contract_id;
        $result = $this->paymentRepository->delete($paymentId);

        if ($result && $wasAdvancePayment) {
            $this->updateActualAdvanceAmount($actualContractId);
        }

        return $result;
    }
    
    public function getTotalPaidAmountForContract(int $contractId, int $organizationId): float
    {
        $this->getContractOrFail($contractId, $organizationId);
        return $this->paymentRepository->getTotalPaidAmountForContract($contractId);
    }
} 