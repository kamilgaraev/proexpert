<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentApproved;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentRejected;
use App\BusinessModules\Core\Payments\Events\PaymentApprovalRequested;
use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Events\PaymentRequestReceived;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovalRequiredNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovedNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentRejectedNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentRequestReceivedNotification;
use App\BusinessModules\Core\Payments\Services\PaymentNotificationRecipients;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class SendPaymentNotifications
{
    public function __construct(private readonly PaymentNotificationRecipients $recipients) {}

    /**
     * Регистрация listeners
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            PaymentApprovalRequested::class,
            [self::class, 'handleApprovalRequested']
        );

        $events->listen(
            PaymentDocumentApproved::class,
            [self::class, 'handleDocumentApproved']
        );

        $events->listen(
            PaymentDocumentRejected::class,
            [self::class, 'handleDocumentRejected']
        );

        $events->listen(
            PaymentRequestReceived::class,
            [self::class, 'handleRequestReceived']
        );
    }

    /**
     * Обработка отправки документа на утверждение
     */
    public function handleApprovalRequested(PaymentApprovalRequested $event): void
    {
        try {
            $document = $event->document->fresh();
            if (! $document || $document->status !== PaymentDocumentStatus::PENDING_APPROVAL) {
                return;
            }

            $approvals = $document->approvals()
                ->where('organization_id', $document->organization_id)
                ->where('approval_level', $event->level)
                ->where('status', 'pending')
                ->whereNull('notified_at')
                ->get();
            $notifiedUserIds = [];

            foreach ($approvals as $approval) {
                $recipients = $this->recipients->forApproval($document, $approval);
                foreach ($recipients as $user) {
                    if (! in_array($user->id, $notifiedUserIds, true)) {
                        $user->notify(new PaymentApprovalRequiredNotification($document));
                        $notifiedUserIds[] = $user->id;
                    }
                }
                if ($recipients->isNotEmpty()) $approval->markAsNotified();
            }

            Log::info('payment_notifications.document_submitted', [
                'document_id' => $document->id,
                'approvers_notified' => count($notifiedUserIds),
            ]);
        } catch (\Exception $e) {
            Log::error('payment_notifications.document_submitted_failed', [
                'document_id' => $event->document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка утверждения документа
     */
    public function handleDocumentApproved(PaymentDocumentApproved $event): void
    {
        try {
            $document = $event->document;
            $notifiedCreatorId = null;

            // Уведомляем создателя документа
            if ($document->created_by_user_id) {
                $creator = User::find($document->created_by_user_id);
                if ($creator && ($this->recipients->allows($creator, $document, 'payments.invoice.view')
                    || $this->recipients->allows($creator, $document, 'payments.invoice.create'))) {
                    $creator->notify(new PaymentApprovedNotification($document));
                    $notifiedCreatorId = $creator->id;
                }
            }

            $financialUsers = $this->recipients->usersWithAnyPermission((int) $document->organization_id, [
                'payments.transaction.register',
            ], $document);

            foreach ($financialUsers as $user) {
                if ($user->id !== $notifiedCreatorId) {
                    $user->notify(new PaymentApprovedNotification($document));
                }
            }

            Log::info('payment_notifications.document_approved', [
                'document_id' => $document->id,
                'approved_by' => $event->approvedByUserId,
            ]);
        } catch (\Exception $e) {
            Log::error('payment_notifications.document_approved_failed', [
                'document_id' => $event->document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка отклонения документа
     */
    public function handleDocumentRejected(PaymentDocumentRejected $event): void
    {
        try {
            $document = $event->document;

            // Уведомляем создателя документа
            if ($document->created_by_user_id) {
                $creator = User::find($document->created_by_user_id);
                if ($creator) {
                    $creator->notify(new PaymentRejectedNotification($document, $event->reason));
                }
            }

            Log::info('payment_notifications.document_rejected', [
                'document_id' => $document->id,
                'rejected_by' => $event->rejectedByUserId,
                'reason' => $event->reason,
            ]);
        } catch (\Exception $e) {
            Log::error('payment_notifications.document_rejected_failed', [
                'document_id' => $event->document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Обработка получения платежного требования
     */
    public function handleRequestReceived(PaymentRequestReceived $event): void
    {
        try {
            $request = $event->request;
            $contractor = Contractor::find($event->contractorId);

            $responsibleUsers = $this->recipients->usersWithAnyPermission((int) $request->organization_id, [
                'payments.invoice.create',
                'payments.invoice.issue',
            ]);

            foreach ($responsibleUsers as $user) {
                $user->notify(new PaymentRequestReceivedNotification(
                    $request,
                    $contractor?->name ?? 'Неизвестный контрагент'
                ));
            }

            Log::info('payment_notifications.request_received', [
                'request_id' => $request->id,
                'contractor_id' => $event->contractorId,
                'notified_users' => $responsibleUsers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('payment_notifications.request_received_failed', [
                'request_id' => $event->request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
