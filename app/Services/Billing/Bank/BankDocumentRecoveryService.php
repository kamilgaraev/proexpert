<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use App\Models\SystemAdmin;
use App\Services\Filament\SystemAdminAuditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class BankDocumentRecoveryService
{
    public function __construct(private readonly BankBillingAccess $access) {}

    public function resolve(SystemAdmin $actor, int $documentId, ?string $providerId, string $reason, bool $confirmed): void
    {
        $this->access->operator($actor);
        if (! $confirmed || mb_strlen(trim($reason)) < 10 || mb_strlen($reason) > 1000
            || ($providerId !== null && ! Str::isUuid($providerId)) || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException(trans_message('bank_billing.invalid'));
        }
        Cache::lock('billing:bank-document:'.$documentId, 300)->block(2, function () use ($actor, $documentId, $providerId, $reason): void {
            $document = CommercialBankDocument::query()->findOrFail($documentId);
            if ($document->provider !== 'tochka' || $document->provider_document_id !== null
                || ! in_array($document->status, ['creation_unknown', 'blocked'], true)
                || (int) $document->organization_id !== (int) $document->invoice->organization_id) {
                throw new InvalidArgumentException(trans_message('bank_billing.invalid'));
            }
            if ($providerId !== null) {
                app(TochkaClient::class)->documentPdf($document->kind, $document->provider_customer_code, $providerId);
            }
            DB::transaction(function () use ($document, $actor, $providerId, $reason): void {
                $before = ['status' => $document->status, 'provider_document_id' => $document->provider_document_id];
                if ($providerId === null) {
                    $document->forceFill(['provider_request' => null,
                        'provider_customer_code' => (string) config('bank_billing.customer_code'), 'creation_started_at' => null]);
                }
                $document->forceFill(['provider_document_id' => $providerId, 'status' => 'pending', 'failure_code' => null,
                    'next_attempt_at' => null, 'reviewed_by' => $actor->id, 'review_note' => trim($reason), 'reviewed_at' => now()])->save();
                app(SystemAdminAuditService::class)->record(actor: $actor, eventType: 'billing.bank_document.resolved', action: 'updated',
                    subjectType: CommercialBankDocument::class, subjectId: (int) $document->id,
                    organizationId: (int) $document->organization_id, before: $before,
                    after: ['status' => 'pending', 'provider_document_id' => $providerId],
                    context: ['resolution' => $providerId === null ? 'absence_confirmed' : 'existing_document_verified']);
            });
        });
    }
}
