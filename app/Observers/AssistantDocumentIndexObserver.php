<?php

declare(strict_types=1);

namespace App\Observers;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;

final class AssistantDocumentIndexObserver
{
    public function saved(AIAssistantDocument $document): void
    {
        if (($document->status === AIAssistantDocument::STATUS_READY && $document->wasChanged(['status', 'checksum', 'processed_at']))
            || ($document->wasChanged('status') && $document->getRawOriginal('status') === AIAssistantDocument::STATUS_READY)) {
            $this->queue($document);
        }
    }

    public function deleted(AIAssistantDocument $document): void
    {
        $this->queue($document);
    }

    private function queue(AIAssistantDocument $document): void
    {
        $transactional = \Illuminate\Support\Facades\DB::transactionLevel() > 0;
        try {
            if ($document->status !== AIAssistantDocument::STATUS_READY || ! $document->exists) {
                app(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexer::class)->deleteIndexedEntity(
                    (int) $document->organization_id, 'file_document', 'assistant_document', (string) $document->id,
                );
            }
            if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
                return;
            }
            app(RagIndexingCoordinator::class)->queueEntity((int) $document->organization_id,
                $document->project_id, 'file_document', 'assistant_document', (string) $document->id);
        } catch (\Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            \Illuminate\Support\Facades\Log::warning('ai_assistant.document.index_pending_failed', [
                'organization_id' => $document->organization_id, 'document_id' => $document->id, 'exception_class' => $exception::class,
            ]);
        }
    }
}
