<?php

namespace App\BusinessModules\Core\Payments\Observers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentAuditService;
use App\Services\Analytics\EVMService;
use Illuminate\Support\Facades\Log;

class PaymentDocumentObserver
{
    public function __construct(
        private readonly PaymentAuditService $auditService
    ) {}

    public function created(PaymentDocument $document): void
    {
        try {
            $this->auditService->logCreated($document);
        } catch (\Exception $e) {
            Log::error('payment_audit.created_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->invalidateEVMCache($document);
    }

    public function updated(PaymentDocument $document): void
    {
        try {
            $changes = $document->getChanges();

            if (! empty($changes)) {
                $formattedChanges = [];

                foreach ($changes as $field => $newValue) {
                    $formattedChanges[$field] = [$document->getOriginal($field), $newValue];
                }

                $this->auditService->logUpdated($document, $formattedChanges);
            }
        } catch (\Exception $e) {
            Log::error('payment_audit.updated_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($this->shouldInvalidateEVMCache($document)) {
            $this->invalidateEVMCache($document, true);
        }
    }

    public function deleted(PaymentDocument $document): void
    {
        try {
            $this->auditService->log(
                'deleted',
                $document,
                $document->getOriginal(),
                null,
                "Удален документ №{$document->document_number}"
            );
        } catch (\Exception $e) {
            Log::error('payment_audit.deleted_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->invalidateEVMCache($document, true);
    }

    public function restored(PaymentDocument $document): void
    {
        $this->invalidateEVMCache($document);
    }

    public function forceDeleted(PaymentDocument $document): void
    {
        $this->invalidateEVMCache($document, true);
    }

    private function shouldInvalidateEVMCache(PaymentDocument $document): bool
    {
        return $document->wasChanged([
            'project_id',
            'invoiceable_type',
            'invoiceable_id',
            'source_type',
            'source_id',
            'paid_amount',
            'status',
            'paid_at',
            'document_date',
        ]);
    }

    private function invalidateEVMCache(PaymentDocument $document, bool $includeOriginal = false): void
    {
        try {
            app(EVMService::class)->invalidateCacheForPaymentDocument($document, $includeOriginal);
        } catch (\Exception $e) {
            Log::warning('Failed to invalidate EVM cache for payment document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
