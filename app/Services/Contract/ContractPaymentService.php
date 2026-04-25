<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Exception;
use Illuminate\Support\Collection;

class ContractPaymentService
{
    public function __construct(
        private readonly ContractPaymentDocumentService $contractPaymentDocumentService,
        private readonly ContractRepositoryInterface $contractRepository,
    ) {
    }

    protected function canAccessContract(Contract $contract, int $organizationId): bool
    {
        if ($contract->organization_id === $organizationId) {
            return true;
        }

        if (!$contract->contractor_id) {
            return false;
        }

        $contract->loadMissing('contractor');

        return $contract->contractor !== null
            && (
                (int) $contract->contractor->organization_id === $organizationId
                || (int) $contract->contractor->source_organization_id === $organizationId
            );
    }

    protected function getContractOrFail(int $contractId, int $organizationId, ?int $projectId = null): Contract
    {
        $contract = $this->contractRepository->find($contractId);

        if (!$contract instanceof Contract) {
            throw new Exception("Contract with ID {$contractId} not found.");
        }

        if (!$this->canAccessContract($contract, $organizationId)) {
            throw new Exception("Contract with ID {$contractId} does not belong to organization {$organizationId}.");
        }

        if ($projectId === null) {
            return $contract;
        }

        if ($contract->is_multi_project) {
            if (!$contract->projects()->where('projects.id', $projectId)->exists()) {
                throw new Exception("Multi-project contract with ID {$contractId} is not linked to project {$projectId}.");
            }

            return $contract;
        }

        if ((int) $contract->project_id !== $projectId) {
            throw new Exception("Contract with ID {$contractId} does not belong to project {$projectId}.");
        }

        return $contract;
    }

    protected function updateActualAdvanceAmount(int $contractId): void
    {
        $this->contractRepository->update($contractId, [
            'actual_advance_amount' => $this->contractPaymentDocumentService->getAdvancePaymentsSum($contractId),
        ]);
    }

    public function getAllPaymentsForContract(
        int $contractId,
        int $organizationId,
        array $filters = [],
        ?int $projectId = null
    ): Collection {
        $this->getContractOrFail($contractId, $organizationId, $projectId);

        return $this->contractPaymentDocumentService->getPaymentsForContract($contractId, $filters);
    }

    public function createPaymentForContract(
        int $contractId,
        int $organizationId,
        ContractPaymentDTO $paymentDTO,
        ?int $projectId = null
    ): PaymentDocument {
        $contract = $this->getContractOrFail($contractId, $organizationId, $projectId);
        $payment = $this->contractPaymentDocumentService->createPaidContractPayment($contract, $paymentDTO->toArray());

        if ($paymentDTO->payment_type->value === 'advance') {
            $this->updateActualAdvanceAmount($contractId);
        }

        return $payment;
    }

    public function getPaymentById(int $paymentId, ?int $contractId, int $organizationId): ?PaymentDocument
    {
        $payment = PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->whereKey($paymentId)
            ->first();

        if (!$payment instanceof PaymentDocument) {
            return null;
        }

        $contract = $this->contractRepository->find((int) $payment->invoiceable_id);

        if (!$contract instanceof Contract || !$this->canAccessContract($contract, $organizationId)) {
            throw new Exception('Payment not found or does not belong to the organization.');
        }

        if ($contractId !== null && (int) $payment->invoiceable_id !== $contractId) {
            return null;
        }

        return $payment;
    }

    public function updatePayment(
        int $paymentId,
        ?int $contractId,
        int $organizationId,
        ContractPaymentDTO $paymentDTO
    ): PaymentDocument {
        $payment = $this->getPaymentById($paymentId, $contractId, $organizationId);

        if (!$payment instanceof PaymentDocument) {
            throw new Exception('Payment not found.');
        }

        $metadata = $payment->metadata ?? [];
        $oldPaymentType = $metadata['contract_payment_type'] ?? null;

        $payment->update([
            'amount' => $paymentDTO->amount,
            'document_date' => $paymentDTO->payment_date,
            'due_date' => $paymentDTO->payment_date,
            'invoice_type' => $this->contractPaymentDocumentService
                ->mapContractPaymentTypeToInvoiceType($paymentDTO->payment_type->value)
                ->value,
            'description' => $paymentDTO->description,
            'metadata' => array_merge($metadata, [
                'contract_payment_type' => $paymentDTO->payment_type->value,
                'reference_document_number' => $paymentDTO->reference_document_number,
            ]),
        ]);

        if ($oldPaymentType === 'advance' || $paymentDTO->payment_type->value === 'advance') {
            $this->updateActualAdvanceAmount((int) $payment->invoiceable_id);
        }

        return $payment->refresh();
    }

    public function deletePayment(int $paymentId, ?int $contractId, int $organizationId): bool
    {
        $payment = $this->getPaymentById($paymentId, $contractId, $organizationId);

        if (!$payment instanceof PaymentDocument) {
            throw new Exception('Payment not found.');
        }

        $wasAdvancePayment = ($payment->metadata['contract_payment_type'] ?? null) === 'advance'
            || $payment->invoice_type?->value === 'advance';
        $actualContractId = (int) $payment->invoiceable_id;

        $result = $payment->update([
            'status' => PaymentDocumentStatus::CANCELLED,
            'remaining_amount' => 0,
        ]);

        if ($result && $wasAdvancePayment) {
            $this->updateActualAdvanceAmount($actualContractId);
        }

        return $result;
    }

    public function getTotalPaidAmountForContract(int $contractId, int $organizationId, ?int $projectId = null): float
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);

        return $this->contractPaymentDocumentService->getTotalPaidAmountForContract($contractId);
    }
}
