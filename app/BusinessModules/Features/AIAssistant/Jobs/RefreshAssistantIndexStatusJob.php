<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RefreshAssistantIndexStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 90;

    public function __construct(
        public int $organizationId,
        public int $actorId,
        public KnowledgeSurface $surface,
        public string $cacheKey,
    ) {
        $this->onConnection('redis');
        $this->onQueue('default');
    }

    public function handle(AssistantIndexStatusService $status, AssistantDataAccessPolicy $policy): void
    {
        $previousSurface = $policy->trustedSurface();
        try {
            $policy->setTrustedSurface($this->surface);
            $status->refreshSnapshot($this->organizationId, $this->actorId, $this->surface, $this->cacheKey);
        } finally {
            $policy->setTrustedSurface($previousSurface);
            Cache::forget($this->cacheKey.':queued');
        }
    }

    public function failed(Throwable $exception): void
    {
        Cache::forget($this->cacheKey.':queued');
    }
}
