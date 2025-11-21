<?php

namespace App\BusinessModules\Core\Payments\Services\Integrations;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Support\Facades\Log;

class BudgetControlService
{
    /**
     * Check if the payment fits within the budget
     * This is a mock implementation as the BudgetEstimates module interface is not fully defined
     */
    public function checkBudget(PaymentDocument $document): array
    {
        if (!$document->project_id) {
            return ['allowed' => true, 'reason' => 'No project assigned'];
        }

        // In a real implementation, we would query the BudgetEstimates module
        // $budget = BudgetEstimates::getForProject($document->project_id);
        // $available = $budget->getAvailableAmount($document->cost_item_id);

        // Mock logic
        $isOverBudget = false; // Default safe assumption
        
        if ($isOverBudget) {
            return [
                'allowed' => false,
                'reason' => 'Exceeds budget limit for Cost Item X',
                'limit' => 100000,
                'current' => 120000
            ];
        }

        return ['allowed' => true];
    }

    /**
     * Validate payment against budget rules before approval
     * @throws \DomainException
     */
    public function validateForApproval(PaymentDocument $document): void
    {
        $check = $this->checkBudget($document);

        if (!$check['allowed']) {
            // Check if strict mode is enabled in settings
            // $strict = config('payments.budget_control_strict', true);
            $strict = true; 

            if ($strict) {
                throw new \DomainException("Budget violation: {$check['reason']}");
            } else {
                Log::warning('payment_document.budget_warning', [
                    'document_id' => $document->id,
                    'reason' => $check['reason']
                ]);
            }
        }
    }
}

