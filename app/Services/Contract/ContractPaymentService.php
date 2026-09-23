<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ContractPaymentService
{
    public function __construct(
        private readonly ContractPaymentDocumentService $contractPaymentDocumentService,
        private readonly ContractRepositoryInterface $contractRepository,
        private readonly ContractAuditedMutationService $contractMutations,
    ) {}

    protected function canAccessContract(Contract $contract, int $organizationId): bool
    {
        if ($contract->organization_id === $organizationId) {
            return true;
        }

        if (! $contract->contractor_id) {
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

        if (! $contract instanceof Contract) {
            throw new Exception("Contract with ID {$contractId} not found.");
        }

        if (! $this->canAccessContract($contract, $organizationId)) {
            throw new Exception("Contract with ID {$contractId} does not belong to organization {$organizationId}.");
        }

        if ($projectId === null) {
            return $contract;
        }

        if ($contract->is_multi_project) {
            if (! $contract->projects()->where('projects.id', $projectId)->exists()) {
                throw new Exception("Multi-project contract with ID {$contractId} is not linked to project {$projectId}.");
            }

            return $contract;
        }

        if ((int) $contract->project_id !== $projectId) {
            throw new Exception("Contract with ID {$contractId} does not belong to project {$projectId}.");
        }

        return $contract;
    }

    protected function updateActualAdvanceAmount(int $contractId, int $paymentId, string $reason): void
    {
        DB::transaction(function () use ($contractId, $paymentId, $reason): void {
            $contract = Contract::query()->whereKey($contractId)->lockForUpdate()->first();
            if (! $contract instanceof Contract) {
                throw new Exception("Contract with ID {$contractId} not found.");
            }

            $this->contractMutations->update(
                $contract,
                ['actual_advance_amount' => $this->contractPaymentDocumentService->getAdvancePaymentsSum($contractId)],
                'actual_advance_amount_recalculated',
                Auth::id(),
                [
                    'payment_id' => $paymentId,
                    'reason' => $reason,
                    'source_event_id' => 'payment:'.(string) $paymentId.':advance_total:'.$reason,
                ],
            );
        }, 3);
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
        return DB::transaction(function () use ($contractId, $organizationId, $paymentDTO, $projectId): PaymentDocument {
            $contract = Contract::query()->whereKey($contractId)->lockForUpdate()->first();
            if (! $contract instanceof Contract || ! $this->canAccessContract($contract, $organizationId)) {
                throw new Exception("Contract with ID {$contractId} not found.");
            }
            $this->getContractOrFail($contractId, $organizationId, $projectId);
            $payment = $this->contractPaymentDocumentService->createPaidContractPayment($contract, $paymentDTO->toArray());

            return $payment;
        }, 3);
    }

    public function getPaymentById(int $paymentId, ?int $contractId, int $organizationId): ?PaymentDocument
    {
        $payment = PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->whereKey($paymentId)
            ->first();

        if (! $payment instanceof PaymentDocument) {
            return null;
        }

        $contract = $this->contractRepository->find((int) $payment->invoiceable_id);

        if (! $contract instanceof Contract || ! $this->canAccessContract($contract, $organizationId)) {
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
        return DB::transaction(function () use ($paymentId, $contractId, $organizationId, $paymentDTO): PaymentDocument {
            $payment = $this->getPaymentById($paymentId, $contractId, $organizationId);
            if (! $payment instanceof PaymentDocument) {
                throw new Exception('Payment not found.');
            }

            $lockedContract = Contract::query()->whereKey($payment->invoiceable_id)->lockForUpdate()->firstOrFail();
            if (! $this->canAccessContract($lockedContract, $organizationId)) {
                throw new Exception('Payment not found.');
            }
            $payment = PaymentDocument::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $metadata = $payment->metadata ?? [];
            $oldPaymentType = $metadata['contract_payment_type'] ?? null;

            $payment = $this->contractPaymentDocumentService->updateUnpaidDocument($payment, [
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
                $this->updateActualAdvanceAmount((int) $payment->invoiceable_id, (int) $payment->id, 'updated');
            }

            return $payment->refresh();
        }, 3);
    }

    public function deletePayment(int $paymentId, ?int $contractId, int $organizationId): bool
    {
        return DB::transaction(function () use ($paymentId, $contractId, $organizationId): bool {
            $payment = $this->getPaymentById($paymentId, $contractId, $organizationId);
            if (! $payment instanceof PaymentDocument) {
                throw new Exception('Payment not found.');
            }

            $actualContractId = (int) $payment->invoiceable_id;
            $lockedContract = Contract::query()->whereKey($actualContractId)->lockForUpdate()->firstOrFail();
            if (! $this->canAccessContract($lockedContract, $organizationId)) {
                throw new Exception('Payment not found.');
            }
            $payment = PaymentDocument::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();
            $wasAdvancePayment = ($payment->metadata['contract_payment_type'] ?? null) === 'advance'
                || $payment->invoice_type?->value === 'advance';

            $this->contractPaymentDocumentService->cancelDocument(
                $payment,
                trans_message('payments.documents.cancelled'),
            );

            if ($wasAdvancePayment) {
                $this->updateActualAdvanceAmount($actualContractId, (int) $payment->id, 'cancelled');
            }

            return true;
        }, 3);
    }

    public function getTotalPaidAmountForContract(int $contractId, int $organizationId, ?int $projectId = null): float
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);

        return $this->contractPaymentDocumentService->getTotalPaidAmountForContract($contractId);
    }
}
