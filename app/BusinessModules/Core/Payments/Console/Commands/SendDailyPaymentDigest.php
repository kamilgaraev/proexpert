<?php

namespace App\BusinessModules\Core\Payments\Console\Commands;

use App\BusinessModules\Core\Payments\Notifications\DailyPaymentDigest;
use App\BusinessModules\Core\Payments\Services\PaymentDocumentService;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class SendDailyPaymentDigest extends Command
{
    protected $signature = 'payments:send-daily-digest';
    protected $description = 'Send daily payment digest to managers';

    public function handle(PaymentDocumentService $service): void
    {
        $organizations = Organization::where('is_active', true)->get();

        foreach ($organizations as $org) {
            // Get statistics
            $stats = $service->getStatistics($org->id);
            $overdue = $service->getOverdue($org->id);
            
            $digestData = [
                'total_balance' => $stats['total_amount'], // This should be calculated properly
                'incoming_today' => 0, // Placeholder
                'outgoing_today' => 0, // Placeholder
                'overdue_count' => $overdue->count(),
                'approval_pending' => $stats['by_status']['pending_approval'] ?? 0,
            ];

            // Find managers (this logic should be refined based on Roles/Permissions)
            $managers = User::where('organization_id', $org->id)
                // ->whereHas('roles', fn($q) => $q->whereIn('name', ['admin', 'accountant']))
                ->get();

            if ($managers->isNotEmpty()) {
                Notification::send($managers, new DailyPaymentDigest($digestData));
                $this->info("Sent digest to {$managers->count()} managers in org {$org->id}");
            }
        }
    }
}

