<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AuthorizeBackgroundAssistantDocumentOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public function __construct(public readonly int $documentId) {}

    public function handle(AssistantDocumentBudgetService $budgets): void
    {
        $document = AIAssistantDocument::query()->find($this->documentId);
        if ($document !== null) {
            $budgets->authorizeBackground($document);
        }
    }
}
