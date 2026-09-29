<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RefreshRagCoverageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 7200;

    public function __construct(public int $organizationId, public ?int $projectId, public ?string $sourceType, public string $cacheKey)
    {
        $this->onConnection((string) config('ai-assistant.rag.queue_connection', 'redis_ai_rag'));
        $this->onQueue((string) config('ai-assistant.rag.live_queue', 'ai-rag-live'));
    }

    public function handle(RagCoverageService $coverage): void
    {
        try {
            $coverage->refreshCoverage($this->organizationId, $this->projectId, $this->sourceType);
        } finally {
            Cache::forget($this->cacheKey.':queued');
        }
    }

    public function failed(Throwable $exception): void
    {
        Cache::forget($this->cacheKey.':queued');
    }
}
