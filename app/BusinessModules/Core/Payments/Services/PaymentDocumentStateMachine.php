<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentApproved;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentRejected;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentSubmitted;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Log;

use function trans_message;

class PaymentDocumentStateMachine
{
    /**
     * Разрешенные переходы между статусами
     */
    private const ALLOWED_TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'], // Черновик → Отправлен или Отменён
        'submitted' => ['pending_approval', 'approved', 'rejected', 'cancelled'], // Отправлен → На согласование/Утверждён/Отклонён
        'pending_approval' => ['approved', 'rejected', 'submitted', 'cancelled'],
        'approved' => ['scheduled', 'paid', 'partially_paid', 'cancelled'], // Утверждён → Запланирован/Оплачен/Частично оплачен/Отменён
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
        if ($document->status === $newStatus) {
            return $document; // Уже в этом статусе
        }

        if (! $this->canTransition($document, $newStatus)) {
            throw new \DomainException(trans_message('payments.workflow.transition_forbidden', [
                'from' => $document->status->label(),
                'to' => $newStatus->label(),
            ]));
        }

        $oldStatus = $document->status;
        $document->status = $newStatus;

        // Установка timestamp в зависимости от статуса
        match ($newStatus) {
            PaymentDocumentStatus::SUBMITTED => $document->submitted_at = now(),
            PaymentDocumentStatus::APPROVED => $document->approved_at = now(),
            PaymentDocumentStatus::SCHEDULED => $document->scheduled_at = $document->scheduled_at ?? now(),
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
        $result = $this->transition($document, PaymentDocumentStatus::SUBMITTED, trans_message('payments.workflow.submitted'));
        event(new PaymentDocumentSubmitted($document));

        return $result;
    }

    /**
     * Отправить на утверждение
     */
    public function sendForApproval(PaymentDocument $document): PaymentDocument
    {
        if (! $document->requiresApproval()) {
            // Если утверждение не требуется, сразу утверждаем
            return $this->approve($document);
        }

        return $this->transition($document, PaymentDocumentStatus::PENDING_APPROVAL, trans_message('payments.workflow.pending_approval'));
    }

    /**
     * Утвердить документ
     */
    public function approve(PaymentDocument $document, ?int $approvedByUserId = null): PaymentDocument
    {
        if ($approvedByUserId) {
            $document->approved_by_user_id = $approvedByUserId;
        }

        $result = $this->transition($document, PaymentDocumentStatus::APPROVED, trans_message('payments.workflow.approved'));
        event(new PaymentDocumentApproved($document, $approvedByUserId ?? 0));

        return $result;
    }

    /**
     * Отклонить документ
     */
    public function reject(PaymentDocument $document, string $reason): PaymentDocument
    {
        $document->notes = ($document->notes ? $document->notes."\n\n" : '')
            .trans_message('payments.workflow.rejected_note', ['reason' => $reason]);
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

        return $this->transition($document, PaymentDocumentStatus::SCHEDULED, trans_message('payments.workflow.scheduled'));
    }

    /**
     * Зарегистрировать частичную оплату (умный метод)
     */
    public function registerPartialPayment(PaymentDocument $document, string|int|float $amount): PaymentDocument
    {
        return $this->markPartiallyPaid($document, $amount);
    }

    /**
     * Отметить как частично оплаченный (Legacy метод, лучше использовать registerPartialPayment)
     */
    public function markPartiallyPaid(
        PaymentDocument $document,
        string|int|float $amount,
        ?int $transactionId = null
    ): PaymentDocument {
        $paymentAmount = BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HalfUp);
        if (! $paymentAmount->isPositive()) {
            throw new \InvalidArgumentException(trans_message('payments.validation.payment_amount_positive'));
        }

        $remainingBeforePayment = BigDecimal::of((string) $document->remaining_amount)
            ->toScale(2, RoundingMode::HalfUp);
        if ($paymentAmount->isGreaterThan($remainingBeforePayment)) {
            throw new \DomainException(trans_message('payments.validation.payment_amount_exceeds_remaining'));
        }

        $paidAmount = BigDecimal::of((string) $document->paid_amount)
            ->plus($paymentAmount)
            ->toScale(2, RoundingMode::HalfUp);
        $remainingAmount = BigDecimal::of((string) $document->amount)
            ->minus($paidAmount)
            ->toScale(2, RoundingMode::HalfUp);
        $document->forceFill([
            'paid_amount' => (string) $paidAmount,
            'remaining_amount' => (string) $remainingAmount,
        ]);

        if (! $remainingAmount->isPositive()) {
            return $this->markPaid($document, (string) $paidAmount, $transactionId);
        }

        if ($document->status === PaymentDocumentStatus::PARTIALLY_PAID) {
            $document->save();

            Log::info('payment_document.payment_registered', [
                'document_id' => $document->id,
                'amount' => (string) $paymentAmount,
                'remaining' => $document->remaining_amount,
            ]);

            return $document;
        }

        $result = $this->transition(
            $document,
            PaymentDocumentStatus::PARTIALLY_PAID,
            trans_message('payments.workflow.partially_paid', ['amount' => (string) $paymentAmount])
        );

        return $result;
    }

    public function markPaid(
        PaymentDocument $document,
        string|int|float|null $finalAmount = null,
        ?int $transactionId = null
    ): PaymentDocument {
        if ($finalAmount !== null) {
            $document->paid_amount = (string) BigDecimal::of((string) $finalAmount)
                ->toScale(2, RoundingMode::HalfUp);
        } else {
            $document->paid_amount = $document->amount;
        }

        $document->remaining_amount = '0.00';
        $document->save();

        $result = $this->transition($document, PaymentDocumentStatus::PAID, trans_message('payments.workflow.paid'));

        event(new PaymentDocumentPaid(
            document: $document,
            amount: $document->paid_amount,
            transactionId: $transactionId,
            recognizedAt: $document->paid_at,
            organizationId: (int) $document->organization_id,
            projectId: $document->project_id === null ? null : (int) $document->project_id,
            invoiceableType: $document->invoiceable_type,
            invoiceableId: $document->invoiceable_id === null ? null : (int) $document->invoiceable_id,
            currency: $document->currency,
        ));

        return $result;
    }

    /**
     * Отменить документ
     */
    public function cancel(PaymentDocument $document, string $reason): PaymentDocument
    {
        $document->notes = ($document->notes ? $document->notes."\n\n" : '')
            .trans_message('payments.workflow.cancelled_note', ['reason' => $reason]);
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
            fn ($value) => PaymentDocumentStatus::from($value),
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
        return match ($status) {
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
