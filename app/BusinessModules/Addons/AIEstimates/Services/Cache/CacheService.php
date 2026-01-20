<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheService
{
    protected int $defaultTtl;

    public function __construct(
        protected CacheKeyGenerator $keyGenerator
    ) {
        $this->defaultTtl = config('ai-estimates.cache.ttl', 3600); // 1 час по умолчанию
        Log::info('CacheService instantiated');
    }

    public function getCached(string $cacheKey): ?array
    {
        try {
            $cached = Cache::tags(['ai-estimates'])->get($cacheKey);

            if ($cached) {
                Log::info('[CacheService] Cache hit', ['key' => $cacheKey]);
            }

            return $cached;
        } catch (\Exception $e) {
            Log::error('[CacheService] Failed to get from cache', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function storeCached(string $cacheKey, array $data, ?int $ttl = null): void
    {
        try {
            $ttl = $ttl ?? $this->defaultTtl;

            Cache::tags(['ai-estimates'])->put($cacheKey, $data, $ttl);

            Log::info('[CacheService] Stored in cache', [
                'key' => $cacheKey,
                'ttl' => $ttl,
            ]);
        } catch (\Exception $e) {
            Log::error('[CacheService] Failed to store in cache', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clearCache(int $organizationId): void
    {
        try {
            $tags = $this->keyGenerator->getOrganizationCacheTags($organizationId);
            Cache::tags($tags)->flush();

            Log::info('[CacheService] Cache cleared for organization', [
                'organization_id' => $organizationId,
                'tags' => $tags,
            ]);
        } catch (\Exception $e) {
            Log::error('[CacheService] Failed to clear cache', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clearProjectCache(int $organizationId, int $projectId): void
    {
        try {
            $tags = $this->keyGenerator->getProjectCacheTags($organizationId, $projectId);
            Cache::tags($tags)->flush();

            Log::info('[CacheService] Cache cleared for project', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'tags' => $tags,
            ]);
        } catch (\Exception $e) {
            Log::error('[CacheService] Failed to clear project cache', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function forget(string $cacheKey): void
    {
        try {
            Cache::tags(['ai-estimates'])->forget($cacheKey);

            Log::info('[CacheService] Cache key forgotten', ['key' => $cacheKey]);
        } catch (\Exception $e) {
            Log::error('[CacheService] Failed to forget cache key', [
                'key' => $cacheKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isEnabled(): bool
    {
        return config('ai-estimates.cache.enabled', true);
    }
}
