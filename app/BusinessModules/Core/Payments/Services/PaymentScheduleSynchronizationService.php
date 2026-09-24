<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentSchedule;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class PaymentScheduleSynchronizationService
{
    public function __construct(
        private readonly PaymentScheduleLedgerReconciliationService $reconciliation,
    ) {}

    public function synchronize(int $documentId, int $organizationId, string $source, array $parts): Collection
    {
        $normalized = $this->normalize($source, $parts);

        return DB::transaction(function () use ($documentId, $organizationId, $source, $normalized): Collection {
            $document = PaymentDocument::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->findOrFail($documentId);
            $total = BigDecimal::zero()->toScale(2);
            foreach ($normalized as $part) {
                $total = $total->plus($part['amount']);
            }
            if (! $total->isEqualTo((string) $document->amount)) {
                throw new DomainException(trans_message('payments.schedule.sum_mismatch'));
            }

            $installments = PaymentSchedule::query()
                ->where('payment_document_id', $document->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $existingSource = $document->schedule_source;
            if (($existingSource !== null && $existingSource !== $source)
                || $installments->contains(fn (PaymentSchedule $row): bool =>
                    ! is_string($row->source_key) || ! str_starts_with($row->source_key, $source.':'))) {
                throw new DomainException(trans_message('payments.schedule.source_conflict'));
            }

            $byKey = $installments->keyBy('source_key');
            $nextNumber = (int) $installments->max('installment_number');
            $changed = false;
            foreach ($normalized as $key => $part) {
                $installment = $byKey->get($key);
                if (! $installment instanceof PaymentSchedule) {
                    $installment = new PaymentSchedule([
                        'payment_document_id' => $document->id,
                        'source_key' => $key,
                        'installment_number' => ++$nextNumber,
                        'paid_amount' => '0.00',
                        'status' => 'pending',
                    ]);
                }
                $installment->fill($part);
                if ($installment->isDirty()) {
                    $installment->save();
                    $changed = true;
                }
            }

            foreach ($installments as $installment) {
                if (array_key_exists($installment->source_key, $normalized)) {
                    continue;
                }
                $installment->fill(['amount' => '0.00', 'due_date' => null]);
                if ($installment->isDirty()) {
                    $installment->save();
                    $changed = true;
                }
            }

            if ($existingSource === null) {
                $document->forceFill(['schedule_source' => $source])->save();
            }
            if ($changed) {
                $this->reconciliation->reconcile($document);
            }

            return PaymentSchedule::query()
                ->where('payment_document_id', $document->id)
                ->orderBy('installment_number')
                ->get();
        });
    }

    public function lockForManualChange(PaymentDocument $document): PaymentDocument
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Payment schedule changes require a transaction.');
        }

        $document = PaymentDocument::query()
            ->where('organization_id', $document->organization_id)
            ->lockForUpdate()
            ->findOrFail($document->id);
        if ($document->schedule_source !== null
            || PaymentSchedule::query()
                ->where('payment_document_id', $document->id)
                ->whereNotNull('source_key')
                ->exists()) {
            throw new DomainException(trans_message('payments.schedule.managed_edit_forbidden'));
        }

        return $document;
    }

    private function normalize(string $source, array $parts): array
    {
        if (! preg_match('/\A[a-z][a-z0-9_]{0,29}\z/', $source)) {
            throw new DomainException(trans_message('payments.validation_error'));
        }

        $normalized = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                throw new DomainException(trans_message('payments.validation_error'));
            }
            $key = $part['source_key'] ?? null;
            $amount = $part['amount_minor'] ?? null;
            $date = $part['due_date'] ?? null;
            if (! is_string($key) || ! preg_match('/\A[a-zA-Z0-9:_-]{1,120}\z/', $key)
                || ! is_int($amount) || $amount <= 0 || $amount > 999999999999999
                || ! array_key_exists('due_date', $part) || ! $this->validDate($date)
                || array_key_exists($source.':'.$key, $normalized)) {
                throw new DomainException(trans_message('payments.validation_error'));
            }
            $normalized[$source.':'.$key] = [
                'amount' => (string) BigDecimal::of($amount)->dividedBy(100, 2),
                'due_date' => $date,
            ];
        }

        return $normalized;
    }

    private function validDate(mixed $date): bool
    {
        if ($date === null) {
            return true;
        }
        if (! is_string($date) || ! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            || str_starts_with($date, '0000')) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }
}
