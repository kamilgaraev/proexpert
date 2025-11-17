<?php

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\BusinessModules\Core\Payments\Enums\InvoiceStatus;
use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Enums\PaymentTransactionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * ВНИМАНИЕ: Эта миграция переносит данные из contract_payments
     * в новые таблицы invoices и payment_transactions.
     * 
     * Запускать только после бэкапа БД!
     */
    public function up(): void
    {
        // Проверка существования таблицы
        if (!Schema::hasTable('contract_payments')) {
            \Log::warning('payments.migration: contract_payments table not found, skipping migration');
            return;
        }

        \Log::info('payments.migration: Starting migration from contract_payments');

        DB::transaction(function () {
            $contractPayments = DB::table('contract_payments')->get();
            $migratedCount = 0;
            
            foreach ($contractPayments as $cp) {
                try {
                    // Получить данные договора
                    $contract = DB::table('contracts')->find($cp->contract_id);
                    
                    if (!$contract) {
                        \Log::warning("payments.migration: Contract {$cp->contract_id} not found for payment {$cp->id}");
                        continue;
                    }

                    // Определить тип счёта
                    $invoiceType = $this->mapPaymentType($cp->payment_type ?? 'progress');

                    // Создать invoice
                    $invoiceId = DB::table('invoices')->insertGetId([
                        'organization_id' => $contract->organization_id,
                        'project_id' => $contract->project_id,
                        'contractor_id' => $contract->contractor_id ?? null,
                        'invoiceable_type' => 'App\\Models\\Contract',
                        'invoiceable_id' => $cp->contract_id,
                        'invoice_number' => 'MIGRATED-' . date('Ymd') . '-' . $cp->id,
                        'invoice_date' => $cp->payment_date ?? $cp->created_at,
                        'due_date' => $cp->payment_date ?? $cp->created_at,
                        'direction' => InvoiceDirection::OUTGOING->value,
                        'invoice_type' => $invoiceType,
                        'total_amount' => $cp->amount,
                        'paid_amount' => $cp->amount,
                        'remaining_amount' => 0,
                        'currency' => 'RUB',
                        'status' => InvoiceStatus::PAID->value,
                        'description' => $cp->description ?? "Мигрировано из contract_payment #{$cp->id}",
                        'issued_at' => $cp->created_at,
                        'paid_at' => $cp->created_at,
                        'created_at' => $cp->created_at,
                        'updated_at' => $cp->updated_at,
                    ]);

                    // Создать transaction
                    DB::table('payment_transactions')->insert([
                        'invoice_id' => $invoiceId,
                        'organization_id' => $contract->organization_id,
                        'project_id' => $contract->project_id,
                        'payee_contractor_id' => $contract->contractor_id ?? null,
                        'amount' => $cp->amount,
                        'currency' => 'RUB',
                        'payment_method' => PaymentMethod::BANK_TRANSFER->value,
                        'reference_number' => $cp->reference_document_number ?? null,
                        'transaction_date' => $cp->payment_date ?? $cp->created_at,
                        'status' => PaymentTransactionStatus::COMPLETED->value,
                        'notes' => "Мигрировано из contract_payment #{$cp->id}",
                        'created_at' => $cp->created_at,
                        'updated_at' => $cp->updated_at,
                    ]);

                    $migratedCount++;

                } catch (\Exception $e) {
                    \Log::error('payments.migration: Error migrating payment', [
                        'contract_payment_id' => $cp->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            \Log::info('payments.migration: Migration completed', [
                'total' => $contractPayments->count(),
                'migrated' => $migratedCount,
            ]);
        });

        // НЕ удаляем таблицу автоматически для безопасности
        // Администратор должен вручную удалить после проверки
        \Log::warning('payments.migration: contract_payments table preserved for safety. Drop manually after verification.');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем мигрированные данные
        DB::transaction(function () {
            DB::table('payment_transactions')
                ->where('notes', 'like', 'Мигрировано из contract_payment%')
                ->delete();

            DB::table('invoices')
                ->where('invoice_number', 'like', 'MIGRATED-%')
                ->delete();
        });

        \Log::info('payments.migration: Rollback completed');
    }

    /**
     * Маппинг типов платежей
     */
    private function mapPaymentType(?string $oldType): string
    {
        return match($oldType) {
            'advance' => InvoiceType::ADVANCE->value,
            'fact_payment' => InvoiceType::PROGRESS->value,
            'deferred_payment' => InvoiceType::FINAL->value,
            default => InvoiceType::PROGRESS->value,
        };
    }
};

