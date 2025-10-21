<?php

namespace App\Listeners;

use App\Events\EstimateApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class NotifyEstimateApprovedListener implements ShouldQueue
{
    public function handle(EstimateApproved $event): void
    {
        $estimate = $event->estimate;
        
        Log::info('Смета утверждена', [
            'estimate_id' => $estimate->id,
            'estimate_number' => $estimate->number,
            'total_amount' => $estimate->total_amount,
            'approved_by' => $estimate->approved_by_user_id,
            'approved_at' => $estimate->approved_at,
        ]);
        
        // Здесь можно добавить отправку уведомлений пользователям
    }
}

