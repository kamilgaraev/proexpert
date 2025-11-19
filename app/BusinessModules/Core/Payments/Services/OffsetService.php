<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис взаимозачета (Offset/Netting)
 */
class OffsetService
{
    public function __construct(
        private readonly CounterpartyAccountService $accountService,
        private readonly PaymentDocumentStateMachine $stateMachine,
        private readonly PaymentAuditService $auditService
    ) {}

    /**
     * Выполнить взаимозачет между двумя документами
     */
    public function performOffset(
        PaymentDocument $receivable,
        PaymentDocument $payable,
        float $amount,
        string $notes = ''
    ): array {
        DB::beginTransaction();

        try {
            // Валидация
            $this->validateOffset($receivable, $payable, $amount);

            // Создаем транзакции взаимозачета
            $transactions = $this->createOffsetTransactions($receivable, $payable, $amount, $notes);

            // Обновляем документы
            $this->updateDocuments($receivable, $payable, $amount);

            // Обновляем счета контрагентов
            $this->accountService->recalculateBalance(
                $receivable->organization_id,
                $receivable->payer_contractor_id ?? $receivable->payee_contractor_id
            );

            // Логируем
            $this->auditService->log(
                'offset_performed',
                $receivable,
                ['remaining_amount' => $receivable->remaining_amount + $amount],
                ['remaining_amount' => $receivable->remaining_amount],
                "Выполнен взаимозачет на сумму {$amount} с документом №{$payable->document_number}"
            );

            Log::info('offset.performed', [
                'receivable_id' => $receivable->id,
                'payable_id' => $payable->id,
                'amount' => $amount,
            ]);

            DB::commit();

            return [
                'success' => true,
                'transactions' => $transactions,
                'receivable' => $receivable->fresh(),
                'payable' => $payable->fresh(),
                'message' => 'Взаимозачет выполнен успешно',
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('offset.failed', [
                'receivable_id' => $receivable->id,
                'payable_id' => $payable->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Автоматический взаимозачет для контрагента
     */
    public function autoOffsetForContractor(int $organizationId, int $contractorId): array
    {
        DB::beginTransaction();

        try {
            // Получаем дебиторскую задолженность (нам должны)
            $receivables = PaymentDocument::where('organization_id', $organizationId)
                ->where('payer_contractor_id', $contractorId)
                ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
                ->where('remaining_amount', '>', 0)
                ->orderBy('due_date', 'asc')
                ->get();

            // Получаем кредиторскую задолженность (мы должны)
            $payables = PaymentDocument::where('organization_id', $organizationId)
                ->where('payee_contractor_id', $contractorId)
                ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
                ->where('remaining_amount', '>', 0)
                ->orderBy('due_date', 'asc')
                ->get();

            if ($receivables->isEmpty() || $payables->isEmpty()) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Нет документов для взаимозачета',
                    'offsets' => [],
                ];
            }

            $offsets = [];
            
            // Выполняем взаимозачет
            foreach ($receivables as $receivable) {
                if ($receivable->remaining_amount <= 0) {
                    continue;
                }

                foreach ($payables as $payable) {
                    if ($payable->remaining_amount <= 0) {
                        continue;
                    }

                    $offsetAmount = min($receivable->remaining_amount, $payable->remaining_amount);
                    
                    if ($offsetAmount > 0) {
                        $result = $this->performOffset(
                            $receivable,
                            $payable,
                            $offsetAmount,
                            'Автоматический взаимозачет'
                        );

                        $offsets[] = $result;
                        
                        // Обновляем остатки
                        $receivable->refresh();
                        $payable->refresh();
                    }
                }
            }

            DB::commit();

            return [
                'success' => true,
                'message' => 'Автоматический взаимозачет выполнен',
                'offsets_count' => count($offsets),
                'total_amount' => collect($offsets)->sum(fn($o) => $o['transactions'][0]->amount ?? 0),
                'offsets' => $offsets,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('auto_offset.failed', [
                'organization_id' => $organizationId,
                'contractor_id' => $contractorId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Валидация взаимозачета
     */
    private function validateOffset(
        PaymentDocument $receivable,
        PaymentDocument $payable,
        float $amount
    ): void {
        // Проверка организации
        if ($receivable->organization_id !== $payable->organization_id) {
            throw new \InvalidArgumentException('Документы принадлежат разным организациям');
        }

        // Проверка контрагента
        $receivableContractor = $receivable->payer_contractor_id;
        $payableContractor = $payable->payee_contractor_id;
        
        if ($receivableContractor !== $payableContractor) {
            throw new \InvalidArgumentException('Документы относятся к разным контрагентам');
        }

        // Проверка статусов
        $allowedStatuses = ['approved', 'scheduled', 'partially_paid'];
        if (!in_array($receivable->status->value, $allowedStatuses)) {
            throw new \DomainException('Дебиторский документ не может участвовать в взаимозачете (статус: ' . $receivable->status->label() . ')');
        }

        if (!in_array($payable->status->value, $allowedStatuses)) {
            throw new \DomainException('Кредиторский документ не может участвовать в взаимозачете (статус: ' . $payable->status->label() . ')');
        }

        // Проверка сумм
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма взаимозачета должна быть больше нуля');
        }

        if ($amount > $receivable->remaining_amount) {
            throw new \InvalidArgumentException('Сумма взаимозачета превышает остаток дебиторской задолженности');
        }

        if ($amount > $payable->remaining_amount) {
            throw new \InvalidArgumentException('Сумма взаимозачета превышает остаток кредиторской задолженности');
        }

        // Проверка валют
        if ($receivable->currency !== $payable->currency) {
            throw new \InvalidArgumentException('Документы в разных валютах. Необходима конвертация');
        }
    }

    /**
     * Создать транзакции взаимозачета
     */
    private function createOffsetTransactions(
        PaymentDocument $receivable,
        PaymentDocument $payable,
        float $amount,
        string $notes
    ): array {
        $transactions = [];

        // Транзакция для дебиторского документа
        $transactions[] = PaymentTransaction::create([
            'organization_id' => $receivable->organization_id,
            'invoice_id' => $receivable->id,
            'transaction_date' => Carbon::now(),
            'amount' => $amount,
            'payment_method' => 'offset',
            'status' => 'completed',
            'payer_contractor_id' => $receivable->payer_contractor_id,
            'description' => "Взаимозачет с документом №{$payable->document_number}. {$notes}",
            'reference_number' => $this->generateOffsetReferenceNumber(),
            'metadata' => [
                'offset_type' => 'receivable',
                'paired_document_id' => $payable->id,
                'paired_transaction_amount' => $amount,
            ],
        ]);

        // Транзакция для кредиторского документа
        $transactions[] = PaymentTransaction::create([
            'organization_id' => $payable->organization_id,
            'invoice_id' => $payable->id,
            'transaction_date' => Carbon::now(),
            'amount' => $amount,
            'payment_method' => 'offset',
            'status' => 'completed',
            'payee_contractor_id' => $payable->payee_contractor_id,
            'description' => "Взаимозачет с документом №{$receivable->document_number}. {$notes}",
            'reference_number' => $this->generateOffsetReferenceNumber(),
            'metadata' => [
                'offset_type' => 'payable',
                'paired_document_id' => $receivable->id,
                'paired_transaction_amount' => $amount,
            ],
        ]);

        return $transactions;
    }

    /**
     * Обновить документы после взаимозачета
     */
    private function updateDocuments(
        PaymentDocument $receivable,
        PaymentDocument $payable,
        float $amount
    ): void {
        // Обновляем дебиторский документ
        $this->stateMachine->registerPartialPayment($receivable, $amount);
        
        // Обновляем кредиторский документ
        $this->stateMachine->registerPartialPayment($payable, $amount);
    }

    /**
     * Генерировать номер взаимозачета
     */
    private function generateOffsetReferenceNumber(): string
    {
        return 'OFFSET-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }

    /**
     * Получить возможные документы для взаимозачета
     */
    public function getOffsetOpportunities(int $organizationId, int $contractorId): array
    {
        $receivables = PaymentDocument::where('organization_id', $organizationId)
            ->where('payer_contractor_id', $contractorId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->where('remaining_amount', '>', 0)
            ->get();

        $payables = PaymentDocument::where('organization_id', $organizationId)
            ->where('payee_contractor_id', $contractorId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->where('remaining_amount', '>', 0)
            ->get();

        $totalReceivable = $receivables->sum('remaining_amount');
        $totalPayable = $payables->sum('remaining_amount');
        $possibleOffset = min($totalReceivable, $totalPayable);

        return [
            'contractor_id' => $contractorId,
            'receivables' => $receivables,
            'payables' => $payables,
            'total_receivable' => round($totalReceivable, 2),
            'total_payable' => round($totalPayable, 2),
            'possible_offset_amount' => round($possibleOffset, 2),
            'net_position' => round($totalReceivable - $totalPayable, 2),
        ];
    }
}

