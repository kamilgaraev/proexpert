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
    public function confirmReceipt(PaymentDocument $document, int $userId, ?string $comment = null): bool
    {
        DB::beginTransaction();

        try {
            if (!$document->hasRegisteredRecipient()) {
                throw new \DomainException(trans_message('payments.validation.recipient_not_registered'));
            }

            $recipientOrgId = $document->getRecipientOrganizationId();
            $user = User::query()->findOrFail($userId);

            $belongsToRecipientOrganization = (int) $user->current_organization_id === (int) $recipientOrgId
                || $user->organizations()
                    ->where('organizations.id', $recipientOrgId)
                    ->wherePivot('is_active', true)
                    ->exists();

            if (!$belongsToRecipientOrganization) {
                throw new \DomainException(trans_message('payments.validation.recipient_confirm_forbidden'));
            }

            $document->confirmByRecipient($userId, $comment);

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
