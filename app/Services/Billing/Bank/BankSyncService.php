<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankStatement;
use App\Models\CommercialBankWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class BankSyncService
{
    public function __construct(private readonly TochkaClient $client, private readonly BankSettlementService $settlement) {}

    public function process(int $limit = 10): array
    {
        app(BankPaymentProofService::class)->processExpired();
        app(BankInvoiceService::class)->expireUnpaid();
        $ready = $this->client->ready();

        $result = Cache::lock('billing:tochka:sync', 1800)->get(function () use ($limit, $ready): array {
            $counts = ['ready' => $ready, 'processed' => 0, 'failed' => 0];
            if ($ready) {
                $account = (string) config('bank_billing.account_id');
                $latest = CommercialBankStatement::query()->where('account_id', $account)
                    ->where('is_periodic', true)->max('statement_date');
                $start = $latest === null ? CarbonImmutable::parse(config('bank_billing.start_date'), 'Europe/Moscow')
                    : CarbonImmutable::parse($latest, 'Europe/Moscow')->subDays((int) config('bank_billing.lookback_days', 3));
                $start = $start->max(CarbonImmutable::parse(config('bank_billing.start_date'), 'Europe/Moscow'));
                $today = CarbonImmutable::now('Europe/Moscow')->startOfDay();
                for ($date = $start, $count = 0; $date->lessThanOrEqualTo($today) && $count < 31; $date = $date->addDay(), $count++) {
                    $this->seed($account, $date->toDateString(), true);
                }
                $events = CommercialBankWebhookEvent::query()->whereNull('processed_at')->oldest('id')->limit(200)->get();
                foreach ($events as $event) {
                    $this->seed($account, $event->operation_date->toDateString());
                }
                $statements = CommercialBankStatement::query()->where('account_id', $account)
                    ->whereIn('status', ['new', 'pending', 'error'])->orderBy('updated_at')->orderBy('statement_date')->limit(max(1, min(10, $limit)))->get();
                foreach ($statements as $statement) {
                    try {
                        if ($this->poll($statement)) {
                            $counts['processed']++;
                        }
                    } catch (Throwable $exception) {
                        $failureCode = $exception instanceof RuntimeException && $exception->getMessage() === 'tochka_statement_pagination_unsupported'
                            ? 'statement_pagination_unsupported' : 'statement_failed';
                        $statement->forceFill(['status' => 'error', 'failure_code' => $failureCode])->save();
                        $counts['failed']++;
                        Log::error('Bank statement processing failed.', ['statement_id' => $statement->id, 'exception' => $exception::class, 'failure_code' => $failureCode]);
                    }
                }
            }
            CommercialBankInvoice::query()->where('status', 'paid')->whereNull('activated_at')
                ->whereHas('order', fn ($query) => $query->where('kind', '!=', 'renewal')->orWhere('period_start_at', '<=', now()))
                ->orderBy('id')->limit(100)->get()
                ->each(function ($invoice) use (&$counts): void {
                    try {
                        $this->settlement->activate((int) $invoice->id);
                    } catch (Throwable $exception) {
                        $counts['failed']++;
                        Log::error('Bank payment activation failed.', ['invoice_id' => $invoice->id, 'exception' => $exception::class]);
                    }
                });
            CommercialBankDocument::query()->where(fn ($query) => $query->whereIn('status', ['pending', 'creating'])
                ->orWhere(fn ($ready) => $ready->where('status', 'ready')->whereNull('notified_at')))
                ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('updated_at')->orderBy('id')->limit(5)->get()
                ->each(function ($document) use (&$counts): void {
                    $document->touch();
                    try {
                        app(BankDocumentService::class)->store($document);
                    } catch (Throwable $exception) {
                        $counts['failed']++;
                        Log::error('Bank document generation failed.', ['document_id' => $document->id, 'exception' => $exception::class]);
                        CommercialBankDocument::query()->whereKey($document->id)->where('status', 'pending')
                            ->whereNull('next_attempt_at')->update(['next_attempt_at' => now()->addMinutes(2)]);
                    }
                });

            return $counts;
        });

        return is_array($result) ? $result : ['ready' => $ready, 'processed' => 0, 'failed' => 0, 'locked' => true];
    }

    private function seed(string $account, string $date, bool $periodic = false): void
    {
        $statement = CommercialBankStatement::query()->firstOrCreate(['account_id' => $account, 'statement_date' => $date]);
        if ($periodic && ! $statement->is_periodic) {
            $statement->forceFill(['is_periodic' => true])->save();
        }
        if ($statement->status === 'done' && $statement->processed_at?->lessThan(now()->subMinutes(2))) {
            $statement->forceFill(['status' => 'new', 'statement_id' => null])->save();
        }
    }

    private function poll(CommercialBankStatement $statement): bool
    {
        $statement->touch();
        if ($statement->statement_id === null || $statement->requested_at?->lessThan(now()->subHours(23))) {
            $id = $this->client->initializeStatement($statement->statement_date->toDateString());
            $statement->forceFill(['statement_id' => $id, 'status' => 'pending', 'requested_at' => now(), 'failure_code' => null])->save();
        }
        $response = $this->client->statement($statement->statement_id);
        if ((int) ($response['Meta']['totalPages'] ?? 1) > 1) {
            throw new RuntimeException('tochka_statement_pagination_unsupported');
        }
        $rows = $response['Data']['Statement'] ?? null;
        if (! is_array($rows)) {
            throw new RuntimeException('tochka_statement_response_invalid');
        }
        $rows = array_is_list($rows) ? $rows : [$rows];
        $row = collect($rows)->first(fn ($row): bool => is_array($row)
            && ($row['accountId'] ?? null) === $statement->account_id
            && ($row['statementId'] ?? null) === $statement->statement_id);
        if (! is_array($row)) {
            throw new RuntimeException('tochka_statement_identity_mismatch');
        }
        if (($row['status'] ?? '') === 'Error') {
            $statement->forceFill(['statement_id' => null])->save();
            throw new RuntimeException('tochka_statement_failed');
        }
        if (($row['status'] ?? '') !== 'Ready') {
            return false;
        }
        if (($row['startDateTime'] ?? '') !== $statement->statement_date->toDateString()
            || ($row['endDateTime'] ?? '') !== $statement->statement_date->toDateString()
            || ! is_array($row['Transaction'] ?? null)) {
            throw new RuntimeException('tochka_statement_range_mismatch');
        }
        $failed = false;
        foreach ($row['Transaction'] as $transaction) {
            try {
                if (! is_array($transaction)) {
                    throw new RuntimeException('tochka_transaction_invalid');
                }
                $this->settlement->ingestFromStatement($transaction, $statement->account_id);
            } catch (Throwable $exception) {
                $failed = true;
                $failureCode = in_array($exception->getMessage(), ['bank_amount_invalid', 'tochka_transaction_invalid', 'tochka_transaction_account_mismatch'], true)
                    ? $exception->getMessage() : 'transaction_failed';
                $amount = is_array($transaction) ? ($transaction['Amount']['amount'] ?? null) : null;
                Log::error('Bank transaction processing failed.', ['statement_id' => $statement->id, 'exception' => $exception::class, 'failure_code' => $failureCode,
                    'amount_type' => get_debug_type($amount),
                    'fraction_digits' => is_string($amount) && preg_match('/\A\d+\.(\d+)\z/', $amount, $matches) ? strlen($matches[1]) : null]);
            }
        }
        if ($failed) {
            throw new RuntimeException('tochka_statement_transaction_failed');
        }
        $statement->forceFill(['status' => 'done', 'processed_at' => now(), 'failure_code' => null])->save();
        CommercialBankWebhookEvent::query()->where('operation_date', $statement->statement_date->toDateString())
            ->whereNull('processed_at')->where('created_at', '<=', $statement->requested_at)
            ->update(['processed_at' => now()]);

        return true;
    }
}
