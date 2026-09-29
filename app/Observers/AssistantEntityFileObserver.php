<?php

declare(strict_types=1);

namespace App\Observers;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\Jobs\RegisterAssistantEntityFile;
use App\Models\File;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

final class AssistantEntityFileObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(File $file): void
    {
        if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
            return;
        }
        if ($file->disk === 's3') {
            RegisterAssistantEntityFile::dispatch((int) $file->id)->afterCommit();
        }
    }

    public function deleted(File $file): void
    {
        if (app(\App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState::class)->paused()) {
            return;
        }
        foreach (AIAssistantDocument::query()->where('file_id', $file->id)->lazyById(50) as $document) {
            app(AssistantDocumentService::class)->failOcr((int) $document->id);
            $document->delete();
        }
    }
}
