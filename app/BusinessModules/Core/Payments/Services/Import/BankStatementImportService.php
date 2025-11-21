<?php

namespace App\BusinessModules\Core\Payments\Services\Import;

use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\Import\Parsers\OneCClientBankParser;
use App\Models\Organization;
use App\Models\Contractor;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BankStatementImportService
{
    public function __construct(
        private readonly OneCClientBankParser $parser
    ) {}

    /**
     * Import bank statement from file content
     */
    public function import(int $organizationId, string $fileContent): array
    {
        $parsedData = $this->parser->parse($fileContent);
        $results = [
            'total' => count($parsedData['documents']),
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
            foreach ($parsedData['documents'] as $docData) {
                try {
                    if ($this->processDocument($organizationId, $docData)) {
                        $results['imported']++;
                    } else {
                        $results['skipped']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = "Doc #{$docData['Номер']}: {$e->getMessage()}";
                }
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Process single document from statement
     */
    private function processDocument(int $organizationId, array $docData): bool
    {
        // Skip non-payment documents
        if (!in_array($docData['Type'], ['Платежное поручение', 'Банковский ордер'])) {
            return false;
        }

        $date = Carbon::createFromFormat('d.m.Y', $docData['Дата']);
        $amount = (float) $docData['Сумма'];
        $number = $docData['Номер'];
        
        // Check for duplicate
        $exists = PaymentTransaction::where('organization_id', $organizationId)
            ->where('reference_number', $number)
            ->where('transaction_date', $date->format('Y-m-d'))
            ->where('amount', $amount)
            ->exists();

        if ($exists) {
            return false;
        }

        // Identify Counterparty
        $payerInn = $docData['ПлательщикИНН'] ?? null;
        $payeeInn = $docData['ПолучательИНН'] ?? null;
        
        $organization = Organization::find($organizationId);
        $isIncoming = ($payeeInn === $organization->inn);

        $contractor = $this->findContractor($isIncoming ? $payerInn : $payeeInn);

        // Create Transaction
        PaymentTransaction::create([
            'organization_id' => $organizationId,
            'amount' => $amount,
            'transaction_date' => $date,
            'reference_number' => $number,
            'payment_method' => 'bank_transfer',
            'status' => 'completed',
            'description' => $docData['НазначениеПлатежа'] ?? '',
            'payer_contractor_id' => $isIncoming ? $contractor?->id : null,
            'payee_contractor_id' => !$isIncoming ? $contractor?->id : null,
            'metadata' => $docData,
        ]);

        return true;
    }

    private function findContractor(?string $inn): ?Contractor
    {
        if (!$inn) return null;
        return Contractor::where('inn', $inn)->first();
    }
}

