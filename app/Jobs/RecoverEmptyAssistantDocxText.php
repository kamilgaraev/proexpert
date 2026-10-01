<?php

declare(strict_types=1);

namespace App\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecoverEmptyAssistantDocxText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 80;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $documentId,
        public readonly string $checksum,
        public readonly string $version,
    ) {}

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(AssistantDocumentService $documents): void
    {
        $documents->recoverEmptyDocx($this->documentId, $this->checksum, $this->version);
    }
}
