<?php

namespace App\BusinessModules\Features\BudgetEstimates\Observers;

use App\Models\Estimate;
use App\BusinessModules\Features\BudgetEstimates\Events\{
    EstimateCreated,
    EstimateApproved,
};

class EstimateObserver
{
    /**
     * Handle the Estimate "created" event.
     */
    public function created(Estimate $estimate): void
    {
        event(new EstimateCreated($estimate));
    }

    /**
     * Handle the Estimate "updated" event.
     */
    public function updated(Estimate $estimate): void
    {
        // Проверить, изменился ли статус на approved
        if ($estimate->isDirty('status') && $estimate->status === 'approved') {
            event(new EstimateApproved($estimate));
        }
    }

    /**
     * Handle the Estimate "deleting" event.
     */
    public function deleting(Estimate $estimate): void
    {
        // Логирование удаления
        \Log::warning('estimate.deleting', [
            'estimate_id' => $estimate->id,
            'estimate_number' => $estimate->number,
            'organization_id' => $estimate->organization_id,
            'status' => $estimate->status,
        ]);
    }

    /**
     * Handle the Estimate "deleted" event.
     */
    public function deleted(Estimate $estimate): void
    {
        \Log::info('estimate.deleted', [
            'estimate_id' => $estimate->id,
            'estimate_number' => $estimate->number,
        ]);
    }
}

