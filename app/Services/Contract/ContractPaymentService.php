<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Models\ContractPayment;
use App\Models\Contract;
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

        return $this->paymentRepository->create($paymentData);
    }

    public function getPaymentById(int $paymentId, int $contractId, int $organizationId): ?ContractPayment
    {
        $this->getContractOrFail($contractId, $organizationId);
        $payment = $this->paymentRepository->find($paymentId);
        if ($payment && $payment->contract_id === $contractId) {
            return $payment;
        }
        return null;
    }

    public function updatePayment(int $paymentId, int $contractId, int $organizationId, ContractPaymentDTO $paymentDTO): ContractPayment
    {
        $this->getContractOrFail($contractId, $organizationId);
        $payment = $this->paymentRepository->find($paymentId);

        if (!$payment || $payment->contract_id !== $contractId) {
            throw new Exception('Payment not found or does not belong to the specified contract.');
        }

        $updateData = $paymentDTO->toArray();
        $updated = $this->paymentRepository->update($paymentId, $updateData);

        if (!$updated) {
            throw new Exception('Failed to update payment.');
        }
        return $this->paymentRepository->find($paymentId); 
    }

    public function deletePayment(int $paymentId, int $contractId, int $organizationId): bool
    {
        $this->getContractOrFail($contractId, $organizationId);
        $payment = $this->paymentRepository->find($paymentId);

        if (!$payment || $payment->contract_id !== $contractId) {
            throw new Exception('Payment not found or does not belong to the specified contract.');
        }
        return $this->paymentRepository->delete($paymentId);
    }
    
    public function getTotalPaidAmountForContract(int $contractId, int $organizationId): float
    {
        $this->getContractOrFail($contractId, $organizationId);
        return $this->paymentRepository->getTotalPaidAmountForContract($contractId);
    }
} 