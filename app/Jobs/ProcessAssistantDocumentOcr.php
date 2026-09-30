<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessAssistantDocumentOcr implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 3; public int $timeout = 80;
    public function __construct(public readonly int $documentId) {}
    public function backoff(): array { return [30, 120, 300]; }
    public function middleware(): array { return [(new \Illuminate\Queue\Middleware\WithoutOverlapping('assistant-ocr:'.$this->documentId))->dontRelease()->expireAfter(180)]; }
    public function handle(AssistantDocumentService $documents): void { $documents->processOcr($this->documentId); }
    public function failed(Throwable $exception): void { app(AssistantDocumentService::class)->failOcr($this->documentId); }
}
