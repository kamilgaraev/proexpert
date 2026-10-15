<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Enums\InvoiceType;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Services\Contract\ContractAuditedMutationService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ContractPaymentLockService
{
    public function lockForPaymentDocument(PaymentDocument $document, ?array $expectedContracts = null): void
    {
        $contractId = $expectedContracts === null
            ? $this->contractIdForPaymentDocument($document)
            : $this->assertContractUnchanged($document, $expectedContracts);
        if ($contractId !== null) {
            Contract::query()->useWritePdo()->whereKey($contractId)->lockForUpdate()->first();
        }
    }

    public function lockForPaymentDocuments(iterable $documents): array
    {
        $expectedContracts = [];
        foreach ($documents as $document) {
            $expectedContracts[(int) $document->id] = $this->contractIdForPaymentDocument($document);
        }
        $ids = array_values(array_unique(array_filter($expectedContracts, static fn (?int $id): bool => $id !== null)));
        if ($ids !== []) {
            Contract::query()->useWritePdo()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        return $expectedContracts;
    }

    public function assertContractUnchanged(PaymentDocument $document, array $expectedContracts): ?int
    {
        $contractId = $this->contractIdForPaymentDocument($document);
        if (! array_key_exists((int) $document->id, $expectedContracts)
            || $contractId !== $expectedContracts[(int) $document->id]) {
            throw (new ModelNotFoundException)->setModel(PaymentDocument::class);
        }

        return $contractId;
    }

    public function contractIdForPaymentDocument(PaymentDocument $document): ?int
    {
        $contractId = null;

        if ($document->invoiceable_type === Contract::class) {
            $contractId = (int) $document->invoiceable_id;
        } elseif ($document->invoiceable_type === ContractPerformanceAct::class) {
            $contractId = (int) ContractPerformanceAct::query()->useWritePdo()
                ->whereKey($document->invoiceable_id)
                ->value('contract_id');
        } elseif ($document->source_type === Contract::class) {
            $contractId = (int) $document->source_id;
        }

        return $contractId > 0 ? $contractId : null;
    }

    public function synchronizeActualAdvanceAmount(PaymentDocument $document, ?int $actorId): void
    {
        if ($document->invoiceable_type !== Contract::class
            || ($document->invoice_type !== InvoiceType::ADVANCE
                && ($document->metadata['contract_payment_type'] ?? null) !== 'advance')) {
            return;
        }

        $contract = Contract::query()->whereKey($document->invoiceable_id)->first();
        if (! $contract instanceof Contract) {
            return;
        }

        $amount = (string) PaymentDocument::query()
            ->where('invoiceable_type', Contract::class)
            ->where('invoiceable_id', $contract->id)
            ->where('status', '!=', PaymentDocumentStatus::CANCELLED->value)
            ->where(function ($query): void {
                $query->where('invoice_type', InvoiceType::ADVANCE->value)
                    ->orWhere('metadata->contract_payment_type', 'advance');
            })
            ->sum('paid_amount');
        $actualAdvance = BigDecimal::of((string) ($contract->actual_advance_amount ?? '0'))
            ->toScale(2, RoundingMode::HalfUp);
        $calculatedAdvance = BigDecimal::of($amount)->toScale(2, RoundingMode::HalfUp);

        if ($actualAdvance->isEqualTo($calculatedAdvance)) {
            return;
        }

        app(ContractAuditedMutationService::class)->update(
            $contract,
            ['actual_advance_amount' => (string) $calculatedAdvance],
            'actual_advance_amount_recalculated',
            $actorId,
            [
                'payment_id' => $document->id,
                'reason' => 'payment_registered',
                'source_event_id' => 'payment:'.(string) $document->id.':advance_total:registered',
            ],
        );
    }
}
