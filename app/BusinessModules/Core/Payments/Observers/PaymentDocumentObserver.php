<?php

namespace App\BusinessModules\Core\Payments\Observers;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PaymentAuditService;

class PaymentDocumentObserver
{
    public function __construct(
        private readonly PaymentAuditService $auditService
    ) {}

    /**
     * Handle the PaymentDocument "created" event.
     */
    public function created(PaymentDocument $document): void
    {
        try {
            $this->auditService->logCreated($document);
        } catch (\Exception $e) {
            \Log::error('payment_audit.created_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the PaymentDocument "updated" event.
     */
    public function updated(PaymentDocument $document): void
    {
        try {
            $changes = $document->getChanges();
            
            if (!empty($changes)) {
                // Преобразуем changes в формат старое -> новое
                $formattedChanges = [];
                foreach ($changes as $field => $newValue) {
                    $oldValue = $document->getOriginal($field);
                    $formattedChanges[$field] = [$oldValue, $newValue];
                }
                
                $this->auditService->logUpdated($document, $formattedChanges);
            }
        } catch (\Exception $e) {
            \Log::error('payment_audit.updated_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the PaymentDocument "deleted" event.
     */
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
            \Log::error('payment_audit.deleted_failed', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

