<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\BusinessModules\Core\Payments\Events\PaymentReceiptConfirmed;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function trans_message;

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
                throw new \DomainException(trans_message('payments.validation.recipient_not_registered'));
            }

            // Проверяем, что текущая организация является получателем
            $recipientOrgId = $document->getRecipientOrganizationId();
            $user = User::query()->findOrFail($userId);

            // Проверяем, что пользователь принадлежит организации-получателю
            if (!$user->isOrganizationOwner($recipientOrgId) && 
                !$user->hasRole('admin', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($recipientOrgId)->id) &&
                !$user->hasRole('finance_admin', \App\Domain\Authorization\Models\AuthorizationContext::getOrganizationContext($recipientOrgId)->id)) {
                
                // Проверяем конкретное разрешение
                if (!$user->can('payments.view', ['organization_id' => $recipientOrgId])) {
                    throw new \DomainException(trans_message('payments.validation.recipient_confirm_forbidden'));
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

            throw new \RuntimeException(sprintf(
                trans_message('payments.validation.recipient_confirm_runtime_error'),
                $e->getMessage()
            ));
        }
    }
}

