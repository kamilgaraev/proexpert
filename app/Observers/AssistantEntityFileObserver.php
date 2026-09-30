<?php

declare(strict_types=1);

namespace App\Observers;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Models\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantEntityFileObserver
{
    public function saved(File $file): void
    {
        $this->safely(function () use ($file): void {
            if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
                return;
            }
            if ($file->disk === 's3') {
                app(RagIndexingCoordinator::class)->queueFileRegistration((int) $file->organization_id, (int) $file->id);
            }
        }, $file);
    }

    public function deleted(File $file): void
    {
        $this->safely(function () use ($file): void {
            foreach (AIAssistantDocument::query()->where('file_id', $file->id)->where('organization_id', $file->organization_id)->lazyById(50) as $document) {
                app(AssistantDocumentService::class)->failOcr((int) $document->id);
                $document->delete();
            }
        }, $file);
    }

    private function safely(callable $operation, File $file): void
    {
        $transactional = DB::transactionLevel() > 0;
        try {
            DB::transaction($operation);
        } catch (Throwable $exception) {
            if ($transactional) {
                throw $exception;
            }
            Log::warning('ai_assistant.file.index_pending_failed', ['file_id' => $file->id, 'exception_class' => $exception::class]);
        }
    }
}
