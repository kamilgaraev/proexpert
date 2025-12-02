<?php

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Events\PaymentReceiptConfirmed;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentConfirmationService
{
    /**
     * Подтвердить получение платежа получателем
     * 
     * Работает только если получатель зарегистрирован и текущая организация = получатель
     * 
     * @param PaymentDocument $document Документ для подтверждения
     * @param int $userId ID пользователя, который подтверждает
     * @param string|null $comment Комментарий получателя
     * @return bool true если успешно подтверждено
     * @throws \DomainException если получатель не зарегистрирован, нет прав или статус не подходит
     */
    public function confirmReceipt(PaymentDocument $document, int $userId, ?string $comment = null): bool
    {
        DB::beginTransaction();

        try {
            // Проверяем, зарегистрирован ли получатель
            if (!$document->hasRegisteredRecipient()) {
                throw new \DomainException('Получатель не зарегистрирован в системе');
            }

            // Проверяем, что текущая организация является получателем
            $recipientOrgId = $document->getRecipientOrganizationId();
            $user = User::findOrFail($userId);

            // Проверяем, что пользователь принадлежит организации-получателю
            if (!$user->isOrganizationOwner($recipientOrgId) && 
                !$user->hasRole('admin', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($recipientOrgId)->id) &&
                !$user->hasRole('finance_admin', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($recipientOrgId)->id)) {
                
                // Проверяем конкретное разрешение
                if (!$user->can('payments.view', ['organization_id' => $recipientOrgId])) {
                    throw new \DomainException('У вас нет прав на подтверждение получения платежа');
                }
            }

            // Подтверждаем получение через метод модели
            $document->confirmByRecipient($userId, $comment);

            // Создаем событие
            event(new PaymentReceiptConfirmed($document, $userId, $comment));

            Log::info('payment_recipient.receipt_confirmed', [
                'document_id' => $document->id,
                'user_id' => $userId,
                'recipient_org_id' => $recipientOrgId,
            ]);

            DB::commit();
            return true;

        } catch (\DomainException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('payment_recipient.confirm_receipt_failed', [
                'document_id' => $document->id,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('Не удалось подтвердить получение платежа: ' . $e->getMessage());
        }
    }
}

