<?php

namespace App\BusinessModules\Core\Payments\Services\Integrations;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\Estimate;
use Illuminate\Support\Facades\Log;

class BudgetControlService
{
    public function checkBudget(PaymentDocument $document): array
    {
        if (!$document->project_id || !$document->estimate_id) {
            return ['allowed' => true, 'reason' => 'No estimate assigned'];
        }

        $estimate = Estimate::query()
            ->where('id', $document->estimate_id)
            ->where('project_id', $document->project_id)
            ->where('organization_id', $document->organization_id)
            ->first();

        if (!$estimate || (float) $estimate->total_amount <= 0) {
            return ['allowed' => true, 'reason' => 'No approved budget amount'];
        }

        $committedStatuses = [
            PaymentDocumentStatus::APPROVED->value,
            PaymentDocumentStatus::SCHEDULED->value,
            PaymentDocumentStatus::PAID->value,
            PaymentDocumentStatus::PARTIALLY_PAID->value,
        ];

        $committedQuery = PaymentDocument::query()
            ->where('organization_id', $document->organization_id)
            ->where('project_id', $document->project_id)
            ->where('estimate_id', $document->estimate_id)
            ->whereIn('status', $committedStatuses);

        if ($document->exists) {
            $committedQuery->whereKeyNot($document->getKey());
        }

        $currentCommitted = (float) $committedQuery->sum('amount');
        $requestedAmount = (float) $document->amount;
        $budgetLimit = (float) $estimate->total_amount;
        $projectedAmount = $currentCommitted + $requestedAmount;

        if ($projectedAmount > $budgetLimit) {
            return [
                'allowed' => false,
                'reason' => trans_message('payments.validation.budget_exceeded'),
                'limit' => $budgetLimit,
                'current' => $currentCommitted,
                'requested' => $requestedAmount,
                'projected' => $projectedAmount,
            ];
        }

        return [
            'allowed' => true,
            'limit' => $budgetLimit,
            'current' => $currentCommitted,
            'requested' => $requestedAmount,
            'projected' => $projectedAmount,
        ];
    }

    public function validateForApproval(PaymentDocument $document): void
    {
        $check = $this->checkBudget($document);

        if (!$check['allowed']) {
            $strict = (bool) config('payments.budget_control_strict', true);

            if ($strict) {
                throw new \DomainException((string) $check['reason']);
            }

            Log::warning('payment_document.budget_warning', [
                'document_id' => $document->id,
                'reason' => $check['reason'],
                'limit' => $check['limit'] ?? null,
                'projected' => $check['projected'] ?? null,
            ]);
        }
    }
}
