<?php

namespace App\Services\Contract;

use App\Repositories\Interfaces\ContractPaymentRepositoryInterface;
use App\Repositories\Interfaces\ContractRepositoryInterface;
use App\DTOs\Contract\ContractPaymentDTO;
use App\Models\ContractPayment;
use App\Models\Contract;
use App\Enums\Contract\ContractPaymentTypeEnum;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Support\Collection;
use Exception;

/**
 * @deprecated Этот сервис устарел. Используйте App\BusinessModules\Core\Payments\Services\InvoiceService
 * Будет удален в версии 2.0
 * 
 * Для миграции:
 * - Авансовые платежи теперь в модуле Payments как Invoice с типом ADVANCE
 * - Используйте InvoiceService для создания/обновления
 * - Или временно используйте LegacyPaymentAdapter для совместимости
 */
class ContractPaymentService
{
    protected ContractPaymentRepositoryInterface $paymentRepository;
    protected ContractRepositoryInterface $contractRepository;
    protected ?ContractStateEventService $stateEventService = null;

    public function __construct(
        ContractPaymentRepositoryInterface $paymentRepository,
        ContractRepositoryInterface $contractRepository
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->contractRepository = $contractRepository;
    }

    /**
     * Получить сервис для работы с событиями состояния договора (lazy loading)
     */
    protected function getStateEventService(): ContractStateEventService
    {
        if ($this->stateEventService === null) {
            $this->stateEventService = app(ContractStateEventService::class);
        }
        return $this->stateEventService;
    }

    /**
     * Проверяет, имеет ли организация доступ к контракту
     * Организация может быть либо заказчиком, либо подрядчиком
     */
    protected function canAccessContract(Contract $contract, int $organizationId): bool
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

    protected function getContractOrFail(int $contractId, int $organizationId, ?int $projectId = null): Contract
    {
        $contract = $this->contractRepository->find($contractId);
        
        if (!$contract) {
            throw new Exception("Contract with ID {$contractId} not found.");
        }
        
        // Проверяем доступ: организация может быть либо заказчиком, либо подрядчиком
        if (!$this->canAccessContract($contract, $organizationId)) {
            $contractorInfo = $contract->contractor_id ? " (contractor_id: {$contract->contractor_id})" : "";
            throw new Exception(
                "Contract with ID {$contractId} does not belong to organization {$organizationId}. " .
                "Contract belongs to organization {$contract->organization_id} (customer).{$contractorInfo}"
            );
        }
        
        // Если указан projectId, проверяем, что контракт принадлежит этому проекту
        if ($projectId !== null) {
            if ($contract->is_multi_project) {
                // Для мультипроектных контрактов проверяем наличие проекта в списке связанных
                // Используем exists() для оптимизации запроса
                $isLinked = $contract->projects()->where('projects.id', $projectId)->exists();
                
                if (!$isLinked) {
                    throw new Exception("Multi-project contract with ID {$contractId} is not linked to project {$projectId}.");
                }
            } elseif ($contract->project_id !== $projectId) {
                throw new Exception("Contract with ID {$contractId} does not belong to project {$projectId}. Contract belongs to project {$contract->project_id}.");
            }
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

    public function getAllPaymentsForContract(int $contractId, int $organizationId, array $filters = [], ?int $projectId = null): Collection
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId); 
        return $this->paymentRepository->getPaymentsForContract($contractId, $filters);
    }

    public function createPaymentForContract(int $contractId, int $organizationId, ContractPaymentDTO $paymentDTO, ?int $projectId = null): ContractPayment
    {
        $contract = $this->getContractOrFail($contractId, $organizationId, $projectId);
        
        $paymentData = $paymentDTO->toArray();
        $paymentData['contract_id'] = $contract->id;

        $payment = $this->paymentRepository->create($paymentData);

        // Если это авансовый платеж - обновляем actual_advance_amount
        if ($paymentDTO->payment_type === ContractPaymentTypeEnum::ADVANCE) {
            $this->updateActualAdvanceAmount($contractId);
        }

        // Создаем событие истории, если контракт использует Event Sourcing
        if ($contract->usesEventSourcing()) {
            try {
                $this->getStateEventService()->createPaymentEvent($contract, $payment);
            } catch (Exception $e) {
                // Не критично, если событие не создалось - логируем и продолжаем
                \Illuminate\Support\Facades\Log::warning('Failed to create payment event', [
                    'payment_id' => $payment->id,
                    'contract_id' => $contract->id,
                    'error' => $e->getMessage()
                ]);
            }
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
        if (!$contract || !$this->canAccessContract($contract, $organizationId)) {
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
        if (!$contract || !$this->canAccessContract($contract, $organizationId)) {
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
        if (!$contract || !$this->canAccessContract($contract, $organizationId)) {
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
    
    public function getTotalPaidAmountForContract(int $contractId, int $organizationId, ?int $projectId = null): float
    {
        $this->getContractOrFail($contractId, $organizationId, $projectId);
        return $this->paymentRepository->getTotalPaidAmountForContract($contractId);
    }
} 