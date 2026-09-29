<?php

declare(strict_types=1);

namespace App\Observers;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class AssistantDocumentIndexObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(AIAssistantDocument $document): void
    {
        if ($document->status === AIAssistantDocument::STATUS_READY && $document->wasChanged(['status', 'checksum', 'processed_at'])) {
            $this->queue($document);
        }
    }

    public function deleted(AIAssistantDocument $document): void
    {
        $this->queue($document);
    }

    private function queue(AIAssistantDocument $document): void
    {
        try {
            if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
                return;
            }
            app(RagIndexingCoordinator::class)->queueEntity((int) $document->organization_id,
                $document->project_id, 'file_document', 'assistant_document', (string) $document->id);
        } catch (\Throwable $exception) {
            \Illuminate\Support\Facades\Log::warning('ai_assistant.document.index_pending_failed', [
                'organization_id' => $document->organization_id, 'document_id' => $document->id, 'exception_class' => $exception::class,
            ]);
        }
    }
}
