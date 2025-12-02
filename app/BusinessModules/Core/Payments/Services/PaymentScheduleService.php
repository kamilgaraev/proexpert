<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentScheduleService
{
    /**
     * Создать график платежей
     */
    public function createSchedule(PaymentDocument $document, array $installments): array
    {
        $schedules = [];
        
        DB::transaction(function () use ($document, $installments, &$schedules) {
            foreach ($installments as $index => $installment) {
                $schedule = PaymentSchedule::create([
                    'payment_document_id' => $document->id,
                    'installment_number' => $index + 1,
                    'due_date' => $installment['due_date'],
                    'amount' => $installment['amount'],
                    'status' => 'pending',
                    'notes' => $installment['notes'] ?? null,
                ]);
                
                $schedules[] = $schedule;
            }
        });

        \Log::info('payments.schedule.created', [
            'payment_document_id' => $document->id,
            'installments_count' => count($schedules),
        ]);

        return $schedules;
    }

    /**
     * Обновить элемент графика
     */
    public function updateSchedule(PaymentSchedule $schedule, array $data): PaymentSchedule
    {
        if ($schedule->isPaid()) {
            throw new \DomainException('Нельзя изменять оплаченный платёж из графика');
        }

        $schedule->update($data);

        return $schedule->fresh();
    }

    /**
     * Отметить платёж графика как оплаченный
     */
    public function markInstallmentPaid(PaymentSchedule $installment, PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($installment, $transaction) {
            $installment->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_transaction_id' => $transaction->id,
            ]);

            \Log::info('payments.schedule.installment_paid', [
                'schedule_id' => $installment->id,
                'transaction_id' => $transaction->id,
            ]);
        });
    }

    /**
     * Получить предстоящие платежи
     */
    public function getUpcomingPayments(int $organizationId, int $days = 7): Collection
    {
        return PaymentSchedule::query()
            ->upcoming($days)
            ->whereHas('paymentDocument', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->with('paymentDocument')
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Получить просроченные платежи графика
     */
    public function getOverdueSchedules(int $organizationId): Collection
    {
        return PaymentSchedule::query()
            ->overdue()
            ->whereHas('paymentDocument', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId);
            })
            ->with('paymentDocument')
            ->orderBy('due_date')
            ->get();
    }
}

