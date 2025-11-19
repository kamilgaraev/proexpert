<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Log::info('payments.migration.started');
        
        // Проверяем, существует ли таблица contract_payments
        if (!DB::getSchemaBuilder()->hasTable('contract_payments')) {
            Log::info('payments.migration.skipped', [
                'reason' => 'Table contract_payments does not exist'
            ]);
            return;
        }
        
        $migratedCount = 0;
        $failedCount = 0;
        
        // Получаем все старые платежи по контрактам
        $oldPayments = DB::table('contract_payments')->get();
        
        foreach ($oldPayments as $oldPayment) {
            try {
                DB::beginTransaction();
                
                // Получаем договор для деталей
                $contract = DB::table('contracts')->find($oldPayment->contract_id);
                
                if (!$contract) {
                    Log::warning('payments.migration.contract_not_found', [
                        'payment_id' => $oldPayment->id,
                        'contract_id' => $oldPayment->contract_id
                    ]);
                    $failedCount++;
                    DB::rollBack();
                    continue;
                }
                
                // Маппинг типов платежей
                $invoiceTypeMap = [
                    'advance' => 'advance',
                    'fact_payment' => 'act',
                    'deferred_payment' => 'progress',
                    'other' => 'other',
                ];
                
                $invoiceType = $invoiceTypeMap[$oldPayment->payment_type] ?? 'other';
                $typePrefix = strtoupper(substr($invoiceType, 0, 3));
                
                // Создаем счет (invoice)
                $invoiceId = DB::table('invoices')->insertGetId([
                    'organization_id' => $contract->organization_id,
                    'project_id' => $contract->project_id,
                    'contractor_id' => $contract->contractor_id,
                    'counterparty_organization_id' => null,
                    'invoiceable_type' => 'App\\Models\\Contract',
                    'invoiceable_id' => $contract->id,
                    'invoice_number' => $oldPayment->reference_document_number ?? "MIG-{$typePrefix}-{$oldPayment->id}",
                    'invoice_date' => $oldPayment->payment_date,
                    'due_date' => $oldPayment->payment_date,
                    'direction' => 'outgoing',
                    'invoice_type' => $invoiceType,
                    'total_amount' => $oldPayment->amount,
                    'paid_amount' => $oldPayment->amount, // Все старые платежи уже оплачены
                    'remaining_amount' => 0,
                    'currency' => 'RUB',
                    'vat_rate' => 20,
                    'vat_amount' => round($oldPayment->amount * 0.20 / 1.20, 2),
                    'amount_without_vat' => round($oldPayment->amount / 1.20, 2),
                    'status' => 'paid',
                    'description' => $oldPayment->description ?? "Платеж по договору (мигрировано, тип: {$oldPayment->payment_type})",
                    'payment_terms' => null,
                    'metadata' => json_encode([
                        'migrated' => true,
                        'old_payment_id' => $oldPayment->id,
                        'old_payment_type' => $oldPayment->payment_type,
                        'old_reference_document_number' => $oldPayment->reference_document_number,
                        'migration_date' => now()->toDateTimeString()
                    ]),
                    'notes' => null,
                    'issued_at' => $oldPayment->payment_date,
                    'paid_at' => $oldPayment->payment_date,
                    'overdue_since' => null,
                    'created_at' => $oldPayment->created_at,
                    'updated_at' => $oldPayment->updated_at,
                    'deleted_at' => null,
                ]);
                
                // Если платеж был оплачен, создаем транзакцию
                if ($oldPayment->payment_date) {
                    DB::table('payment_transactions')->insert([
                        'invoice_id' => $invoiceId,
                        'organization_id' => $contract->organization_id,
                        'project_id' => $contract->project_id,
                        'payer_organization_id' => $contract->organization_id,
                        'payee_organization_id' => null,
                        'payer_contractor_id' => null,
                        'payee_contractor_id' => $contract->contractor_id,
                        'amount' => $oldPayment->amount,
                        'currency' => 'RUB',
                        'payment_method' => 'bank_transfer',
                        'reference_number' => 'MIG-' . $oldPayment->id,
                        'bank_transaction_id' => null,
                        'transaction_date' => $oldPayment->payment_date,
                        'value_date' => $oldPayment->payment_date,
                        'status' => 'completed',
                        'payment_gateway_id' => null,
                        'gateway_response' => null,
                        'proof_document_url' => null,
                        'notes' => 'Мигрировано из старой системы',
                        'metadata' => json_encode([
                            'migrated' => true,
                            'old_payment_id' => $oldPayment->id
                        ]),
                        'created_by_user_id' => null,
                        'approved_by_user_id' => null,
                        'created_at' => $oldPayment->payment_date,
                        'updated_at' => $oldPayment->updated_at,
                    ]);
                }
                
                DB::commit();
                $migratedCount++;
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('payments.migration.failed', [
                    'payment_id' => $oldPayment->id,
                    'error' => $e->getMessage()
                ]);
                $failedCount++;
            }
        }
        
        Log::info('payments.migration.completed', [
            'migrated' => $migratedCount,
            'failed' => $failedCount,
            'total' => $oldPayments->count()
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Удаляем только мигрированные данные
        $migratedInvoices = DB::table('invoices')
            ->whereRaw("metadata->>'migrated' = 'true'")
            ->pluck('id');
        
        DB::table('payment_transactions')->whereIn('invoice_id', $migratedInvoices)->delete();
        DB::table('invoices')->whereIn('id', $migratedInvoices)->delete();
        
        Log::info('payments.migration.rolled_back');
    }
};

