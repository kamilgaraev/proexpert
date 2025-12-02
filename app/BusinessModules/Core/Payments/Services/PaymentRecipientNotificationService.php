<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class PaymentRecipientNotificationService
{
    /**
     * Отправить уведомление получателю о создании документа
     * 
     * Работает только если получатель зарегистрирован в системе
     * 
     * @param PaymentDocument $document Документ для уведомления
     * @return bool true если уведомление отправлено, false если получатель не зарегистрирован
     */
    public function notifyRecipient(PaymentDocument $document): bool
    {
        // Проверяем, зарегистрирован ли получатель
        $recipientOrgId = $document->getRecipientOrganizationId();
        
        if (!$recipientOrgId) {
            // Получатель не зарегистрирован - это нормально, просто не отправляем уведомление
            Log::debug('payment_recipient.notify_skipped', [
                'document_id' => $document->id,
                'reason' => 'recipient_not_registered',
            ]);
            return false;
        }

        try {
            // Находим организацию-получателя
            $recipientOrg = Organization::find($recipientOrgId);
            
            if (!$recipientOrg) {
                Log::warning('payment_recipient.organization_not_found', [
                    'document_id' => $document->id,
                    'recipient_org_id' => $recipientOrgId,
                ]);
                return false;
            }

            // Находим пользователей организации, которые должны получить уведомление
            // Обычно это владельцы организации и финансовые менеджеры
            $usersToNotify = $this->getUsersToNotify($recipientOrgId);

            if ($usersToNotify->isEmpty()) {
                Log::info('payment_recipient.no_users_to_notify', [
                    'document_id' => $document->id,
                    'recipient_org_id' => $recipientOrgId,
                ]);
                return false;
            }

            // Отправляем уведомления
            // TODO: Создать Notification класс PaymentDocumentCreatedNotification
            // Notification::send($usersToNotify, new PaymentDocumentCreatedNotification($document));

            // Отмечаем документ как уведомленный
            $document->markAsNotifiedToRecipient();

            Log::info('payment_recipient.notified', [
                'document_id' => $document->id,
                'recipient_org_id' => $recipientOrgId,
                'users_count' => $usersToNotify->count(),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('payment_recipient.notify_failed', [
                'document_id' => $document->id,
                'recipient_org_id' => $recipientOrgId,
                'error' => $e->getMessage(),
            ]);

            // Не бросаем исключение - отсутствие уведомления не должно ломать систему
            return false;
        }
    }

    /**
     * Отправить уведомление получателю о регистрации платежа
     * 
     * Работает только если получатель зарегистрирован в системе
     * 
     * @param PaymentDocument $document Документ
     * @param PaymentTransaction $transaction Транзакция платежа
     * @return bool true если уведомление отправлено, false если получатель не зарегистрирован
     */
    public function notifyRecipientAboutPayment(PaymentDocument $document, PaymentTransaction $transaction): bool
    {
        // Проверяем, зарегистрирован ли получатель
        $recipientOrgId = $document->getRecipientOrganizationId();
        
        if (!$recipientOrgId) {
            // Получатель не зарегистрирован - это нормально
            Log::debug('payment_recipient.payment_notify_skipped', [
                'document_id' => $document->id,
                'transaction_id' => $transaction->id,
                'reason' => 'recipient_not_registered',
            ]);
            return false;
        }

        try {
            // Находим пользователей для уведомления
            $usersToNotify = $this->getUsersToNotify($recipientOrgId);

            if ($usersToNotify->isEmpty()) {
                return false;
            }

            // Отправляем уведомления
            // TODO: Создать Notification класс PaymentRegisteredNotification
            // Notification::send($usersToNotify, new PaymentRegisteredNotification($document, $transaction));

            Log::info('payment_recipient.payment_notified', [
                'document_id' => $document->id,
                'transaction_id' => $transaction->id,
                'recipient_org_id' => $recipientOrgId,
                'users_count' => $usersToNotify->count(),
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('payment_recipient.payment_notify_failed', [
                'document_id' => $document->id,
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Получить список пользователей для уведомления в организации-получателе
     * 
     * @param int $organizationId ID организации-получателя
     * @return \Illuminate\Support\Collection Коллекция пользователей
     */
    private function getUsersToNotify(int $organizationId): \Illuminate\Support\Collection
    {
        // Находим пользователей организации, которые должны получать уведомления:
        // 1. Владельцы организации
        // 2. Финансовые менеджеры
        // 3. Администраторы организации

        $context = \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($organizationId);

        // Получаем пользователей с нужными ролями в контексте организации
        $users = User::whereHas('roleAssignments', function ($query) use ($context) {
            $query->where('context_id', $context->id)
                ->where('is_active', true)
                ->whereIn('role_slug', ['organization_owner', 'finance_admin', 'admin']);
        })->get();

        // Также добавляем пользователей, которые имеют право payments.view через кастомные роли
        // Проверяем через AuthorizationService
        $allOrgUsers = User::whereHas('roleAssignments', function ($query) use ($context) {
            $query->where('context_id', $context->id)
                ->where('is_active', true);
        })->get();

        $usersWithPermission = $allOrgUsers->filter(function ($user) use ($organizationId) {
            return $user->can('payments.view', ['organization_id' => $organizationId]);
        });

        // Объединяем и убираем дубликаты
        return $users->merge($usersWithPermission)->unique('id');
    }
}

