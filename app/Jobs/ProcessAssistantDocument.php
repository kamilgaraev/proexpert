<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentBudgetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessAssistantDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3;
    public int $timeout = 80;
    public bool $failOnTimeout = true;
    public function __construct(public readonly int $documentId) {}
    public function backoff(): array { return [30, 120, 300]; }
    public function middleware(): array { return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('assistant-extraction:'.$this->documentId))->dontRelease()->expireAfter(180)]; }
    public function handle(AssistantDocumentService $documents, AssistantDocumentBudgetService $budgets): void
    {
        $document = $documents->process($this->documentId);
        try { $budgets->authorizeBackground($document); }
        catch (\Throwable $exception) { \Illuminate\Support\Facades\Log::warning('ai_assistant.document.background_ocr_skipped', ['document_id' => $document->id, 'exception_class' => $exception::class]); }
    }
    public function failed(\Throwable $exception): void { app(AssistantDocumentService::class)->fail($this->documentId); }
}
