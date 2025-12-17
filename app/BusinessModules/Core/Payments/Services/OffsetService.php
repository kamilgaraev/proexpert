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
     * Выполнить взаимозачет между двумя документами с защитой от гонок
     * 
     * @param int $receivableId ID дебиторского документа (нам должны)
     * @param int $payableId ID кредиторского документа (мы должны)
     * @param float $amount Сумма зачета
     * @param string $notes Примечание
     * @return array Результат операции
     * @throws \Exception
     */
    public function performOffset(
        int $receivableId,
        int $payableId,
        float $amount,
        string $notes = ''
    ): array {
        // Используем транзакцию с блокировкой строк (Pessimistic Locking)
        return DB::transaction(function () use ($receivableId, $payableId, $amount, $notes) {
            // 1. Блокируем строки для обновления.
            // Важно блокировать в определенном порядке (например, по ID), чтобы избежать Deadlock
            $firstId = min($receivableId, $payableId);
            $secondId = max($receivableId, $payableId);

            // Находим и блокируем оба документа
            // Используем lockForUpdate(), чтобы другие транзакции ждали завершения этой
            $firstDoc = PaymentDocument::where('id', $firstId)->lockForUpdate()->first();
            $secondDoc = PaymentDocument::where('id', $secondId)->lockForUpdate()->first();

            if (!$firstDoc || !$secondDoc) {
                throw new \InvalidArgumentException('Один из документов не найден');
            }

            // Распределяем обратно по переменным
            $receivable = ($firstDoc->id === $receivableId) ? $firstDoc : $secondDoc;
            $payable = ($firstDoc->id === $payableId) ? $firstDoc : $secondDoc;

            // 2. Валидация (уже на актуальных, заблокированных данных)
            $this->validateOffset($receivable, $payable, $amount);

            try {
                // 3. Создаем транзакции взаимозачета (Immutable)
                $transactions = $this->createOffsetTransactions($receivable, $payable, $amount, $notes);

                // 4. Обновляем документы (State Machine должна корректно обработать частичную оплату)
                $this->updateDocuments($receivable, $payable, $amount);

                // 5. Обновляем счета контрагентов
                $this->accountService->recalculateBalance(
                    $receivable->organization_id,
                    $receivable->payer_contractor_id ?? $receivable->payee_contractor_id
                );

                // 6. Логируем (Аудит)
                $this->auditService->log(
                    'offset_performed',
                    $receivable,
                    ['remaining_amount' => $receivable->remaining_amount + $amount], // Примерно, точнее будет взять из модели
                    ['remaining_amount' => $receivable->remaining_amount],
                    "Выполнен взаимозачет на сумму {$amount} с документом №{$payable->document_number}"
                );

                Log::info('offset.performed', [
                    'receivable_id' => $receivable->id,
                    'payable_id' => $payable->id,
                    'amount' => $amount,
                    'user_id' => auth()->id(),
                ]);

                return [
                    'success' => true,
                    'transactions' => $transactions,
                    'receivable' => $receivable->fresh(),
                    'payable' => $payable->fresh(),
                    'message' => 'Взаимозачет выполнен успешно',
                ];

            } catch (\Exception $e) {
                Log::error('offset.failed', [
                    'receivable_id' => $receivableId,
                    'payable_id' => $payableId,
                    'amount' => $amount,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }

    /**
     * Автоматический взаимозачет для контрагента
     */
    public function autoOffsetForContractor(int $organizationId, int $contractorId): array
    {
        // В автоматическом режиме тоже нужна транзакция
        // Но здесь мы не можем заранее знать ID всех документов для блокировки.
        // Стратегия:
        // 1. Читаем кандидатов
        // 2. В цикле вызываем performOffset, который берет свои маленькие транзакции с блокировками.
        // Это чуть медленнее, но безопаснее и меньше шансов на Deadlock большого диапазона.
        
        // ОДНАКО, для атомарности всей операции авто-зачета лучше обернуть всё.
        // Но блокировать таблицу целиком нельзя.
        // Пойдем по пути итеративного зачета: каждый зачет - отдельная атомарная операция.
        
        $offsets = [];
        $processedCount = 0;
        
        // Получаем кандидатов (snapshot reading)
        $receivables = PaymentDocument::where('organization_id', $organizationId)
            ->where('payer_contractor_id', $contractorId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('due_date', 'asc')
            ->get();

        $payables = PaymentDocument::where('organization_id', $organizationId)
            ->where('payee_contractor_id', $contractorId)
            ->whereIn('status', ['approved', 'scheduled', 'partially_paid'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('due_date', 'asc')
            ->get();

        if ($receivables->isEmpty() || $payables->isEmpty()) {
            return [
                'success' => false,
                'message' => 'Нет документов для взаимозачета',
                'offsets' => [],
            ];
        }

        foreach ($receivables as $receivable) {
            // Перепроверяем актуальность остатка перед поиском пары (оптимизация)
            if ($receivable->remaining_amount <= 0.001) continue;

            foreach ($payables as $payable) {
                if ($payable->remaining_amount <= 0.001) continue;

                // Важно: мы передаем ID, чтобы performOffset сам заблокировал и проверил актуальные данные
                // Внутри performOffset будет повторная проверка remaining_amount
                
                // Вычисляем потенциальную сумму (по snapshot данным)
                // Реальная сумма может быть меньше, если кто-то успел изменить документ
                $offsetAmount = min($receivable->remaining_amount, $payable->remaining_amount);
                
                if ($offsetAmount > 0.001) {
                    try {
                        $result = $this->performOffset(
                            $receivable->id,
                            $payable->id,
                            $offsetAmount,
                            'Автоматический взаимозачет'
                        );

                        $offsets[] = $result;
                        $processedCount++;
                        
                        // Обновляем локальные модели для следующей итерации цикла
                        // (хотя performOffset работает по ID, нам нужны актуальные данные для условий цикла)
                        $receivable->refresh();
                        $payable->refresh();
                        
                    } catch (\Exception $e) {
                        // Если один зачет не прошел (например, документ заблокирован или изменился),
                        // логируем и идем дальше
                        Log::warning('auto_offset.item_failed', [
                            'receivable_id' => $receivable->id,
                            'payable_id' => $payable->id,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        return [
            'success' => true,
            'message' => "Автоматический взаимозачет завершен. Выполнено операций: {$processedCount}",
            'offsets_count' => count($offsets),
            'total_amount' => collect($offsets)->sum(fn($o) => $o['transactions'][0]->amount ?? 0),
            'offsets' => $offsets,
        ];
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
        if ($amount <= 0.009) { // Защита от float near zero
            throw new \InvalidArgumentException('Сумма взаимозачета должна быть больше нуля');
        }

        // Используем bccomp для сравнения float денег, если bcmath доступен, или epsilon
        // Здесь простое сравнение с запасом на float precision
        $epsilon = 0.00001;

        if ($amount > ($receivable->remaining_amount + $epsilon)) {
            throw new \InvalidArgumentException("Сумма взаимозачета ({$amount}) превышает остаток дебиторской задолженности ({$receivable->remaining_amount})");
        }

        if ($amount > ($payable->remaining_amount + $epsilon)) {
            throw new \InvalidArgumentException("Сумма взаимозачета ({$amount}) превышает остаток кредиторской задолженности ({$payable->remaining_amount})");
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
        $now = Carbon::now();
        $ref = $this->generateOffsetReferenceNumber();

        // Транзакция для дебиторского документа
        $transactions[] = PaymentTransaction::create([
            'payment_document_id' => $receivable->id,
            'organization_id' => $receivable->organization_id,
            'project_id' => $receivable->project_id,
            'transaction_date' => $now,
            'amount' => $amount,
            'currency' => $receivable->currency,
            'payment_method' => 'offset', // Убедитесь, что это значение есть в Enum
            'status' => 'completed',
            'payer_contractor_id' => $receivable->payer_contractor_id,
            'notes' => "Взаимозачет с документом №{$payable->document_number}. {$notes}",
            'reference_number' => $ref,
            'metadata' => [
                'offset_type' => 'receivable',
                'paired_document_id' => $payable->id,
                'paired_transaction_amount' => $amount,
            ],
            'created_by_user_id' => auth()->id(),
        ]);

        // Транзакция для кредиторского документа
        $transactions[] = PaymentTransaction::create([
            'payment_document_id' => $payable->id,
            'organization_id' => $payable->organization_id,
            'project_id' => $payable->project_id,
            'transaction_date' => $now,
            'amount' => $amount,
            'currency' => $payable->currency,
            'payment_method' => 'offset',
            'status' => 'completed',
            'payee_contractor_id' => $payable->payee_contractor_id,
            'notes' => "Взаимозачет с документом №{$receivable->document_number}. {$notes}",
            'reference_number' => $ref,
            'metadata' => [
                'offset_type' => 'payable',
                'paired_document_id' => $receivable->id,
                'paired_transaction_amount' => $amount,
            ],
            'created_by_user_id' => auth()->id(),
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
        // Здесь блокировка не нужна, это просто отчет
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
