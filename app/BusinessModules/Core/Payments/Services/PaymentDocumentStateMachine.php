<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentSubmitted;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentApproved;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentRejected;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use Illuminate\Support\Facades\Log;

class PaymentDocumentStateMachine
{
    /**
     * Разрешенные переходы между статусами
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'], // Черновик → Отправлен или Отменён
        'submitted' => ['pending_approval', 'approved', 'rejected', 'cancelled'], // Отправлен → На согласование/Утверждён/Отклонён
        'pending_approval' => ['approved', 'rejected', 'submitted'], // На согласовании → Утверждён/Отклонён/Возврат
        'approved' => ['scheduled', 'paid', 'cancelled'], // Утверждён → Запланирован/Оплачен/Отменён
        'scheduled' => ['paid', 'partially_paid', 'cancelled'], // Запланирован → Оплачен/Частично оплачен/Отменён
        'partially_paid' => ['paid', 'scheduled'], // Частично оплачен → Полностью оплачен/Запланирован
        'paid' => [], // Оплачен - финальный статус
        'rejected' => ['draft'], // Отклонён → можно вернуть в черновик для исправления
        'cancelled' => [], // Отменён - финальный статус
    ];

    /**
     * Проверить возможность перехода
     */
    public function canTransition(PaymentDocument $document, PaymentDocumentStatus $newStatus): bool
    {
        $currentStatus = $document->status->value;
        $allowedStatuses = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        return in_array($newStatus->value, $allowedStatuses);
    }

    /**
     * Выполнить переход статуса
     */
    public function transition(PaymentDocument $document, PaymentDocumentStatus $newStatus, ?string $reason = null): PaymentDocument
    {
        if (!$this->canTransition($document, $newStatus)) {
            throw new \DomainException(
                "Недопустимый переход из статуса '{$document->status->label()}' в '{$newStatus->label()}'"
            );
        }

        $oldStatus = $document->status;
        $document->status = $newStatus;

        // Установка timestamp в зависимости от статуса
        match ($newStatus) {
            PaymentDocumentStatus::SUBMITTED => $document->submitted_at = now(),
            PaymentDocumentStatus::APPROVED => $document->approved_at = now(),
            PaymentDocumentStatus::SCHEDULED => $document->scheduled_at = now(),
            PaymentDocumentStatus::PAID => $document->paid_at = now(),
            default => null,
        };

        $document->save();

        Log::info('payment_document.status_changed', [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'old_status' => $oldStatus->value,
            'new_status' => $newStatus->value,
            'reason' => $reason,
        ]);

        return $document;
    }

    /**
     * Отправить на рассмотрение
     */
    public function submit(PaymentDocument $document): PaymentDocument
    {
        $result = $this->transition($document, PaymentDocumentStatus::SUBMITTED, 'Отправлен на рассмотрение');
        event(new PaymentDocumentSubmitted($document));
        return $result;
    }

    /**
     * Отправить на утверждение
     */
    public function sendForApproval(PaymentDocument $document): PaymentDocument
    {
        if (!$document->requiresApproval()) {
            // Если утверждение не требуется, сразу утверждаем
            return $this->approve($document);
        }

        return $this->transition($document, PaymentDocumentStatus::PENDING_APPROVAL, 'Отправлен на согласование');
    }

    /**
     * Утвердить документ
     */
    public function approve(PaymentDocument $document, ?int $approvedByUserId = null): PaymentDocument
    {
        if ($approvedByUserId) {
            $document->approved_by_user_id = $approvedByUserId;
        }

        $result = $this->transition($document, PaymentDocumentStatus::APPROVED, 'Утвержден');
        event(new PaymentDocumentApproved($document, $approvedByUserId ?? 0));
        return $result;
    }

    /**
     * Отклонить документ
     */
    public function reject(PaymentDocument $document, string $reason): PaymentDocument
    {
        $document->notes = ($document->notes ? $document->notes . "\n\n" : '') . "Отклонено: {$reason}";
        $document->save();

        $result = $this->transition($document, PaymentDocumentStatus::REJECTED, $reason);
        event(new PaymentDocumentRejected($document, $reason, 0));
        return $result;
    }

    /**
     * Запланировать к оплате
     */
    public function schedule(PaymentDocument $document, ?\DateTime $scheduledAt = null): PaymentDocument
    {
        if ($scheduledAt) {
            $document->scheduled_at = $scheduledAt;
            $document->save();
        }

        return $this->transition($document, PaymentDocumentStatus::SCHEDULED, 'Запланирован к оплате');
    }

    /**
     * Зарегистрировать частичную оплату (умный метод)
     */
    public function registerPartialPayment(PaymentDocument $document, float $amount): PaymentDocument
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Сумма оплаты должна быть положительной');
        }

        $document->paid_amount += $amount;
        $document->remaining_amount = $document->calculateRemainingAmount();

        // Используем epsilon для сравнения float
        if ($document->remaining_amount <= 0.001) {
            // Полная оплата
            // Корректируем возможные погрешности округления
            $document->remaining_amount = 0;
            return $this->markPaid($document, $document->paid_amount);
        }

        // Частичная оплата
        // Если статус уже PARTIALLY_PAID, то transition не нужен (и может быть запрещен), просто сохраняем
        if ($document->status === PaymentDocumentStatus::PARTIALLY_PAID) {
            $document->save();
            Log::info('payment_document.payment_registered', [
                'document_id' => $document->id,
                'amount' => $amount,
                'remaining' => $document->remaining_amount,
            ]);
            return $document;
        }

        return $this->transition($document, PaymentDocumentStatus::PARTIALLY_PAID, "Частичная оплата: {$amount}");
    }

    /**
     * Отметить как частично оплаченный (Legacy метод, лучше использовать registerPartialPayment)
     */
    public function markPartiallyPaid(PaymentDocument $document, float $amount, ?int $transactionId = null): PaymentDocument
    {
        $document->paid_amount += $amount;
        $document->remaining_amount = $document->calculateRemainingAmount();
        $document->save();

        $result = $this->transition($document, PaymentDocumentStatus::PARTIALLY_PAID, "Частичная оплата: {$amount}");
        
        if ($transactionId) {
            event(new PaymentDocumentPaid($document, $amount, $transactionId));
        }
        
        return $result;
    }

    public function markPaid(PaymentDocument $document, ?float $finalAmount = null, ?int $transactionId = null): PaymentDocument
    {
        if ($finalAmount !== null) {
            $document->paid_amount = $finalAmount;
        } else {
            $document->paid_amount = $document->amount;
        }

        $document->remaining_amount = 0;
        $document->save();

        $result = $this->transition($document, PaymentDocumentStatus::PAID, 'Полностью оплачен');
        
        event(new PaymentDocumentPaid($document, $document->paid_amount, $transactionId));
        
        return $result;
    }

    /**
     * Отменить документ
     */
    public function cancel(PaymentDocument $document, string $reason): PaymentDocument
    {
        $document->notes = ($document->notes ? $document->notes . "\n\n" : '') . "Отменен: {$reason}";
        $document->save();

        return $this->transition($document, PaymentDocumentStatus::CANCELLED, $reason);
    }

    /**
     * Получить список доступных переходов
     */
    public function getAvailableTransitions(PaymentDocument $document): array
    {
        $currentStatus = $document->status->value;
        $allowedStatusValues = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];

        return array_map(
            fn($value) => PaymentDocumentStatus::from($value),
            $allowedStatusValues
        );
    }

    /**
     * Получить человекочитаемое описание доступных действий
     */
    public function getAvailableActions(PaymentDocument $document): array
    {
        $transitions = $this->getAvailableTransitions($document);
        $actions = [];

        foreach ($transitions as $status) {
            $actions[] = [
                'status' => $status->value,
                'label' => $status->label(),
                'action' => $this->getActionNameForStatus($status),
            ];
        }

        return $actions;
    }

    /**
     * Получить название действия для статуса
     */
    private function getActionNameForStatus(PaymentDocumentStatus $status): string
    {
        return match($status) {
            PaymentDocumentStatus::SUBMITTED => 'submit',
            PaymentDocumentStatus::PENDING_APPROVAL => 'sendForApproval',
            PaymentDocumentStatus::APPROVED => 'approve',
            PaymentDocumentStatus::REJECTED => 'reject',
            PaymentDocumentStatus::SCHEDULED => 'schedule',
            PaymentDocumentStatus::PAID => 'markPaid',
            PaymentDocumentStatus::PARTIALLY_PAID => 'markPartiallyPaid',
            PaymentDocumentStatus::CANCELLED => 'cancel',
            default => 'transition',
        };
    }
}

