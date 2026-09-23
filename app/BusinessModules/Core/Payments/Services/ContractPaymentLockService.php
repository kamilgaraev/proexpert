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

final class ContractPaymentLockService
{
    public function lockForPaymentDocument(PaymentDocument $document): void
    {
        $contractId = null;

        if ($document->invoiceable_type === Contract::class) {
            $contractId = (int) $document->invoiceable_id;
        } elseif ($document->invoiceable_type === ContractPerformanceAct::class) {
            $contractId = (int) ContractPerformanceAct::query()
                ->whereKey($document->invoiceable_id)
                ->value('contract_id');
        } elseif ($document->source_type === Contract::class) {
            $contractId = (int) $document->source_id;
        }

        if ($contractId > 0) {
            Contract::query()->whereKey($contractId)->lockForUpdate()->first();
        }
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
