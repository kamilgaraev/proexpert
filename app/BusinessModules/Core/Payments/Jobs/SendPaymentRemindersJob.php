<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovalRequiredNotification;
use App\BusinessModules\Core\Payments\Services\PaymentNotificationRecipients;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для отправки напоминаний об утверждении платежей
 * Запускается ежедневно через Scheduler
 */
class SendPaymentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(PaymentNotificationRecipients $recipients): void
    {
        Log::info('send_payment_reminders.started');

        $sentCount = 0;

        // Получаем документы ожидающие утверждения более 24 часов
        $pendingDocuments = PaymentDocument::pendingApproval()
            ->where('submitted_at', '<', Carbon::now()->subHours(24))
            ->with(['approvals' => function($query) {
                $query->where('status', 'pending');
            }])
            ->get();

        foreach ($pendingDocuments as $document) {
            try {
                $notifiedUserIds = [];
                foreach ($document->approvals as $approval) {
                    // Проверяем можно ли отправить напоминание
                    if (!$approval->canSendReminder()) {
                        continue;
                    }

                    $users = $recipients->forApproval($document, $approval);
                    foreach ($users as $user) {
                        if (! in_array($user->id, $notifiedUserIds, true)) {
                            $user->notify(new PaymentApprovalRequiredNotification($document));
                            $notifiedUserIds[] = $user->id;
                            $sentCount++;
                        }
                    }
                    if ($users->isNotEmpty()) $approval->markReminderSent();
                }
            } catch (\Exception $e) {
                Log::error('send_payment_reminders.document_failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('send_payment_reminders.completed', [
            'sent_count' => $sentCount,
            'pending_documents' => $pendingDocuments->count(),
        ]);
    }
}

