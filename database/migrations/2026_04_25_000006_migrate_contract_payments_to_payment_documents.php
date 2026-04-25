<?php

declare(strict_types=1);

use App\Models\Contract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('contract_payments')
            || !Schema::hasTable('payment_documents')
            || !Schema::hasTable('payment_transactions')
        ) {
            return;
        }

        DB::table('contract_payments')
            ->orderBy('id')
            ->chunkById(100, function ($payments): void {
                foreach ($payments as $payment) {
                    if ($this->paymentDocumentExists((int) $payment->id)) {
                        continue;
                    }

                    $contract = DB::table('contracts')->where('id', $payment->contract_id)->first();

                    if (!$contract) {
                        continue;
                    }

                    $amount = (float) $payment->amount;
                    $paymentDate = $payment->payment_date ?? $payment->created_at ?? now();
                    $invoiceType = $this->mapPaymentType($payment->payment_type ?? 'other');
                    $metadata = [
                        'legacy_contract_payment_id' => (int) $payment->id,
                        'contract_payment_type' => $payment->payment_type ?? 'other',
                        'reference_document_number' => $payment->reference_document_number ?? null,
                    ];

                    $documentId = DB::table('payment_documents')->insertGetId([
                        'organization_id' => $contract->organization_id,
                        'project_id' => $contract->project_id,
                        'document_type' => 'invoice',
                        'direction' => 'outgoing',
                        'invoice_type' => $invoiceType,
                        'invoiceable_type' => Contract::class,
                        'invoiceable_id' => $contract->id,
                        'contractor_id' => $contract->contractor_id,
                        'payee_contractor_id' => $contract->contractor_id,
                        'payer_organization_id' => $contract->organization_id,
                        'document_number' => 'LEGACY-CP-' . $payment->id,
                        'document_date' => $paymentDate,
                        'amount' => $amount,
                        'currency' => 'RUB',
                        'paid_amount' => $amount,
                        'remaining_amount' => 0,
                        'status' => 'paid',
                        'due_date' => $paymentDate,
                        'description' => $payment->description ?? null,
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                        'issued_at' => $paymentDate,
                        'paid_at' => $paymentDate,
                        'created_at' => $payment->created_at ?? now(),
                        'updated_at' => $payment->updated_at ?? now(),
                    ]);

                    $transactionData = [
                        'payment_document_id' => $documentId,
                        'organization_id' => $contract->organization_id,
                        'project_id' => $contract->project_id,
                        'payee_contractor_id' => $contract->contractor_id,
                        'payer_organization_id' => $contract->organization_id,
                        'amount' => $amount,
                        'currency' => 'RUB',
                        'payment_method' => 'bank_transfer',
                        'reference_number' => $payment->reference_document_number ?? null,
                        'transaction_date' => $paymentDate,
                        'value_date' => $paymentDate,
                        'status' => 'completed',
                        'notes' => $payment->description ?? null,
                        'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
                        'created_at' => $payment->created_at ?? now(),
                        'updated_at' => $payment->updated_at ?? now(),
                    ];

                    if (Schema::hasColumn('payment_transactions', 'invoice_id')) {
                        $transactionData['invoice_id'] = $documentId;
                    }

                    DB::table('payment_transactions')->insert($transactionData);
                }
            });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payment_documents')) {
            return;
        }

        $documentIds = DB::table('payment_documents')
            ->whereNotNull('metadata->legacy_contract_payment_id')
            ->pluck('id');

        if ($documentIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('payment_transactions')) {
            DB::table('payment_transactions')
                ->whereIn('payment_document_id', $documentIds)
                ->delete();
        }

        DB::table('payment_documents')
            ->whereIn('id', $documentIds)
            ->delete();
    }

    private function paymentDocumentExists(int $legacyPaymentId): bool
    {
        return DB::table('payment_documents')
            ->where('metadata->legacy_contract_payment_id', $legacyPaymentId)
            ->exists();
    }

    private function mapPaymentType(string $paymentType): string
    {
        return match ($paymentType) {
            'advance' => 'advance',
            'fact_payment', 'regular' => 'progress',
            'deferred_payment' => 'final',
            default => 'other',
        };
    }
};
