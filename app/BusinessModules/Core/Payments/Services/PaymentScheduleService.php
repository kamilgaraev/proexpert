<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function trans_message;

class PaymentScheduleService
{
    public function __construct(
        private readonly PaymentDocumentService $paymentDocumentService,
    ) {}

    /**
     * @param array<int, array{installment_number: int, due_date: string, amount: int|float|string, notes?: string|null}> $installments
     * @return array<int, PaymentSchedule>
     */
    public function replacePendingSchedule(PaymentDocument $document, array $installments, ?User $user = null): array
    {
        $totalScheduleAmount = (float) collect($installments)->sum('amount');
        if ((float) $document->amount !== $totalScheduleAmount) {
            throw new \DomainException(trans_message('payments.schedule.sum_mismatch'));
        }

        $hasLockedInstallments = PaymentSchedule::query()
            ->where('payment_document_id', $document->id)
            ->where('status', '!=', 'pending')
            ->exists();

        if ($hasLockedInstallments) {
            throw new \DomainException(trans_message('payments.schedule.update_locked'));
        }

        return DB::transaction(function () use ($document, $installments, $user): array {
            PaymentSchedule::query()
                ->where('payment_document_id', $document->id)
                ->where('status', 'pending')
                ->delete();

            $schedules = [];
            foreach ($installments as $installment) {
                $schedules[] = PaymentSchedule::query()->create([
                    'payment_document_id' => $document->id,
                    'installment_number' => $installment['installment_number'],
                    'due_date' => $installment['due_date'],
                    'amount' => $installment['amount'],
                    'status' => 'pending',
                    'notes' => $installment['notes'] ?? null,
                ]);
            }

            $firstPaymentDate = Carbon::parse((string) collect($installments)->min('due_date'))->startOfDay();
            $this->paymentDocumentService->schedule($document, $firstPaymentDate, $user);

            return $schedules;
        });
    }

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
            throw new \DomainException(trans_message('payments.validation.schedule_paid_edit_forbidden'));
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
