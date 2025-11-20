<?php

namespace App\BusinessModules\Core\Payments\Jobs;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Job для уведомления о предстоящих платежах
 * Запускается ежедневно утром через Scheduler
 */
class SendUpcomingPaymentNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private array $daysThresholds = [1, 3, 7]; // за сколько дней уведомлять

    public function __construct() {}

    public function handle(): void
    {
        Log::info('send_upcoming_payment_notifications.started');

        $notifiedCount = 0;

        foreach ($this->daysThresholds as $days) {
            $targetDate = Carbon::now()->addDays($days)->startOfDay();
            $documents = PaymentDocument::awaitingPayment()
                ->whereDate('due_date', $targetDate)
                ->with(['organization', 'payeeContractor'])
                ->get();

            foreach ($documents as $document) {
                try {
                    $this->notifyFinancialTeam($document, $days);
                    $notifiedCount++;
                } catch (\Exception $e) {
                    Log::error('send_upcoming_payment_notifications.failed', [
                        'document_id' => $document->id,
                        'days' => $days,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('send_upcoming_payment_notifications.completed', [
            'notified_count' => $notifiedCount,
        ]);
    }

    /**
     * Уведомить финансовую команду
     */
    private function notifyFinancialTeam(PaymentDocument $document, int $daysUntilDue): void
    {
        // Определяем роли для уведомления
        $roles = ['financial_director', 'chief_accountant', 'accountant'];

        // Получаем пользователей через новую систему авторизации
        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($document->organization_id);
        
        $users = User::whereHas('roleAssignments', function($query) use ($context, $roles) {
            $query->where('context_id', $context->id)
                ->whereIn('role_slug', $roles);
        })->get();

        $notification = new class($document, $daysUntilDue) extends Notification {
            public function __construct(
                public PaymentDocument $document,
                public int $daysUntilDue
            ) {}

            public function via($notifiable): array
            {
                return ['mail', 'database'];
            }

            public function toMail($notifiable): MailMessage
            {
                $urgency = $this->daysUntilDue === 1 ? '⚠️ Завтра' : "Через {$this->daysUntilDue} дней";

                return (new MailMessage)
                    ->subject("{$urgency} - Предстоящий платеж")
                    ->greeting('Здравствуйте!')
                    ->line("{$urgency} требуется оплата по документу №{$this->document->document_number}.")
                    ->line("Получатель: {$this->document->getPayeeName()}")
                    ->line("Сумма: {$this->document->formatted_amount}")
                    ->line("Срок оплаты: " . $this->document->due_date?->format('d.m.Y'))
                    ->action('Перейти к документу', url("/admin/payments/documents/{$this->document->id}"))
                    ->line('Пожалуйста, подготовьте платеж заранее.');
            }

            public function toArray($notifiable): array
            {
                return [
                    'type' => 'upcoming_payment',
                    'document_id' => $this->document->id,
                    'document_number' => $this->document->document_number,
                    'amount' => $this->document->amount,
                    'currency' => $this->document->currency,
                    'payee_name' => $this->document->getPayeeName(),
                    'due_date' => $this->document->due_date?->format('Y-m-d'),
                    'days_until_due' => $this->daysUntilDue,
                    'url' => url("/admin/payments/documents/{$this->document->id}"),
                ];
            }
        };

        foreach ($users as $user) {
            $user->notify($notification);
        }
    }
}

