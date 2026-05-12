<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services\Import;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Enums\PaymentMethod;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\Import\Parsers\OneCClientBankParser;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Contractor;
use App\Models\Organization;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use function trans_message;

class BankStatementImportService
{
    public function __construct(
        private readonly OneCClientBankParser $parser,
        private readonly PaymentDocumentService $paymentDocumentService,
    ) {}

    public function import(int $organizationId, string $fileContent, ?int $userId = null): array
    {
        $parsedData = $this->parser->parse($fileContent);
        $results = [
            'total' => count($parsedData['documents']),
            'imported' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        DB::transaction(function () use ($organizationId, $parsedData, $userId, &$results): void {
            foreach ($parsedData['documents'] as $docData) {
                try {
                    $status = $this->processDocument($organizationId, $docData, $userId);
                    if ($status === 'imported') {
                        $results['imported']++;
                    } else {
                        $results['skipped']++;
                    }
                } catch (\Exception $e) {
                    $results['errors'][] = trans_message('payments.import.document_error', [
                        'number' => (string) ($docData['Номер'] ?? '-'),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $results;
    }

    private function processDocument(int $organizationId, array $docData, ?int $userId): string
    {
        if (!in_array($docData['Type'] ?? null, ['Платежное поручение', 'Банковский ордер'], true)) {
            return 'skipped';
        }

        $date = Carbon::createFromFormat('d.m.Y', (string) $docData['Дата']);
        $amount = $this->normalizeAmount((string) $docData['Сумма']);
        $number = (string) $docData['Номер'];

        if ($this->transactionExists($organizationId, $number, $date, $amount)) {
            return 'skipped';
        }

        $payerInn = $this->normalizeInn($docData['ПлательщикИНН'] ?? null);
        $payeeInn = $this->normalizeInn($docData['ПолучательИНН'] ?? null);
        $organization = Organization::findOrFail($organizationId);
        $organizationInn = $this->normalizeInn($organization->tax_number ?? $organization->inn ?? null);
        $isIncoming = $payeeInn !== null && $payeeInn === $organizationInn;
        $counterpartyInn = $isIncoming ? $payerInn : $payeeInn;
        $contractor = $this->findContractor($organizationId, $counterpartyInn);

        $document = $this->findPaymentDocument($organizationId, $contractor?->id, $amount, $isIncoming);
        if ($document === null) {
            throw new \DomainException(trans_message('payments.import.document_match_not_found', [
                'number' => $number,
            ]));
        }

        $this->paymentDocumentService->registerPayment($document, $amount, [
            'payment_method' => PaymentMethod::BANK_TRANSFER->value,
            'reference_number' => $number,
            'bank_transaction_id' => $number,
            'transaction_date' => $date->toDateString(),
            'value_date' => $date->toDateString(),
            'notes' => $docData['НазначениеПлатежа'] ?? null,
            'metadata' => $docData,
            'created_by_user_id' => $userId,
        ]);

        return 'imported';
    }

    private function transactionExists(int $organizationId, string $number, Carbon $date, float $amount): bool
    {
        return PaymentTransaction::query()
            ->where('organization_id', $organizationId)
            ->where('reference_number', $number)
            ->whereDate('transaction_date', $date->toDateString())
            ->where('amount', $amount)
            ->exists();
    }

    private function findPaymentDocument(int $organizationId, ?int $contractorId, float $amount, bool $isIncoming): ?PaymentDocument
    {
        $query = PaymentDocument::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', [
                PaymentDocumentStatus::APPROVED,
                PaymentDocumentStatus::SCHEDULED,
                PaymentDocumentStatus::PARTIALLY_PAID,
            ])
            ->where('remaining_amount', '>=', $amount);

        if ($contractorId !== null) {
            $query->where(function ($inner) use ($contractorId, $isIncoming): void {
                $inner->where('contractor_id', $contractorId)
                    ->orWhere($isIncoming ? 'payer_contractor_id' : 'payee_contractor_id', $contractorId);
            });
        }

        $documents = $query
            ->orderByRaw('ABS(remaining_amount - ?) ASC', [$amount])
            ->limit(2)
            ->get();

        return $documents->count() === 1 ? $documents->first() : null;
    }

    private function findContractor(int $organizationId, ?string $inn): ?Contractor
    {
        if ($inn === null || $inn === '') {
            return null;
        }

        return Contractor::query()
            ->where('organization_id', $organizationId)
            ->where('inn', $inn)
            ->first();
    }

    private function normalizeAmount(string $amount): float
    {
        return (float) str_replace([' ', ','], ['', '.'], $amount);
    }

    private function normalizeInn(?string $inn): ?string
    {
        if ($inn === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $inn);

        return $normalized !== '' ? $normalized : null;
    }
}
