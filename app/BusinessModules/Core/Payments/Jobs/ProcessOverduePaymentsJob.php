<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentOverdue;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Notifications\PaymentOverdueNotification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job для обработки просроченных платежей
 * Запускается ежедневно через Scheduler
 */
class ProcessOverduePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct() {}

    public function handle(): void
    {
        Log::info('processing_overdue_payments.started');

        $processedCount = 0;
        $notifiedCount = 0;

        // Получаем все просроченные документы
        $overdueDocuments = PaymentDocument::overdue()
            ->with(['organization', 'payeeContractor', 'createdBy'])
            ->get();

        foreach ($overdueDocuments as $document) {
            try {
                $overdueDays = $document->getOverdueDays();

                // Генерируем событие
                event(new PaymentDocumentOverdue($document, $overdueDays));

                // Отправляем уведомления ответственным лицам
                $this->notifyResponsibleUsers($document, $overdueDays);

                // Обновляем метаданные
                $metadata = $document->metadata ?? [];
                $metadata['last_overdue_check'] = now()->toDateTimeString();
                $metadata['overdue_days'] = $overdueDays;
                $document->metadata = $metadata;
                $document->save();

                $processedCount++;
                $notifiedCount++;

            } catch (\Exception $e) {
                Log::error('processing_overdue_payments.document_failed', [
                    'document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('processing_overdue_payments.completed', [
            'processed' => $processedCount,
            'notified' => $notifiedCount,
            'total_overdue' => $overdueDocuments->count(),
        ]);
    }

    /**
     * Уведомить ответственных пользователей
     */
    private function notifyResponsibleUsers(PaymentDocument $document, int $overdueDays): void
    {
        // Определяем кого уведомлять в зависимости от срока просрочки
        $roles = match(true) {
            $overdueDays > 30 => ['general_director', 'financial_director', 'chief_accountant'],
            $overdueDays > 7 => ['financial_director', 'chief_accountant'],
            default => ['chief_accountant', 'accountant'],
        };

        // Получаем пользователей с этими ролями в организации через новую систему авторизации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($document->organization_id);
        
        $users = User::whereHas('roleAssignments', function($query) use ($context, $roles) {
            $query->where('context_id', $context->id)
                ->whereIn('role_slug', $roles);
        })->get();

        // Отправляем уведомления
        foreach ($users as $user) {
            $user->notify(new PaymentOverdueNotification($document, $overdueDays));
        }
    }
}

