<?php

declare(strict_types=1);

namespace App\Services\ActReport;

use App\Models\ContractPerformanceAct;
use App\Notifications\ActReportStatusNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class ActReportNotificationService
{
    public function notifyStatusChanged(ContractPerformanceAct $act, string $message): void
    {
        try {
            $act->loadMissing('contract.organization.users');
            $organization = $act->contract?->organization;

            if (!$organization) {
                return;
            }

            $recipients = $organization->users()
                ->wherePivot('is_active', true)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new ActReportStatusNotification($act, $message));
        } catch (\Throwable $e) {
            Log::warning('act_reports.notification_failed', [
                'act_id' => $act->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
