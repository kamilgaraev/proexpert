<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
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
        $started = hrtime(true);
        $global = $this->projectId === null && $this->sourceType === null;
        AssistantStatusSnapshotDiagnostics::coverageRefresh('started', $global);
        try {
            $result = $coverage->refreshCoverage($this->organizationId, $this->projectId, $this->sourceType, $this->cacheKey);
            AssistantStatusSnapshotDiagnostics::coverageRefresh('finished', $global, (hrtime(true) - $started) / 1_000_000,
                ($result['eligible_count_known'] ?? false) === true,
                is_string($result['projection_generation'] ?? null) && $result['projection_generation'] !== '');
        } catch (Throwable $exception) {
            AssistantStatusSnapshotDiagnostics::coverageRefresh('failed', $global, (hrtime(true) - $started) / 1_000_000, exception: $exception);
            throw $exception;
        } finally {
            Cache::forget($this->cacheKey.':queued');
        }
    }

    public function failed(Throwable $exception): void
    {
        AssistantStatusSnapshotDiagnostics::coverageRefresh('terminal_failed', $this->projectId === null && $this->sourceType === null, exception: $exception);
        Cache::forget($this->cacheKey.':queued');
    }
}
