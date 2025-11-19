<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentApproved;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentRejected;
use App\BusinessModules\Core\Payments\Events\PaymentDocumentSubmitted;
use App\BusinessModules\Core\Payments\Events\PaymentRequestReceived;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovalRequiredNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentApprovedNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentRejectedNotification;
use App\BusinessModules\Core\Payments\Notifications\PaymentRequestReceivedNotification;
use App\Models\Contractor;
use App\Models\User;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

class SendPaymentNotifications
{
    /**
     * Регистрация listeners
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            PaymentDocumentSubmitted::class,
            [self::class, 'handleDocumentSubmitted']
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
    public function handleDocumentSubmitted(PaymentDocumentSubmitted $event): void
    {
        try {
            $document = $event->document;

            // Находим утверждающих первого уровня
            $approvals = $document->approvals()
                ->where('approval_level', 1)
                ->where('status', 'pending')
                ->get();

            foreach ($approvals as $approval) {
                if ($approval->approver_user_id) {
                    $user = User::find($approval->approver_user_id);
                    if ($user) {
                        $user->notify(new PaymentApprovalRequiredNotification($document));
                        $approval->markAsNotified();
                    }
                }
            }

            Log::info('payment_notifications.document_submitted', [
                'document_id' => $document->id,
                'approvers_notified' => $approvals->count(),
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

            // Уведомляем создателя документа
            if ($document->created_by_user_id) {
                $creator = User::find($document->created_by_user_id);
                if ($creator) {
                    $creator->notify(new PaymentApprovedNotification($document));
                }
            }

            // Уведомляем финансовый отдел
            $financialUsers = User::whereHas('organizationUsers', function($query) use ($document) {
                $query->where('organization_id', $document->organization_id)
                    ->whereIn('role', ['financial_director', 'chief_accountant']);
            })->get();

            foreach ($financialUsers as $user) {
                $user->notify(new PaymentApprovedNotification($document));
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

            // Уведомляем ответственных за работу с данным контрагентом
            $responsibleUsers = User::whereHas('organizationUsers', function($query) use ($request) {
                $query->where('organization_id', $request->organization_id)
                    ->whereIn('role', ['financial_director', 'chief_accountant', 'project_manager']);
            })->get();

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

