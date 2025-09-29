<?php

namespace App\Services\Organization;

use App\Models\User;
use App\Models\Organization;
use Illuminate\Support\Facades\Cache;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationCacheService
{
    private const JWT_CACHE_TTL = 3600; // 1 час
    private const ORG_ACCESS_CACHE_TTL = 1800; // 30 минут

    public function getCachedJwtPayload(): ?\Tymon\JWTAuth\Payload
    {
        try {
            $token = JWTAuth::parseToken()->getToken();
            $tokenKey = 'jwt_payload_' . hash('sha256', $token);
            
            return cache()->remember($tokenKey, self::JWT_CACHE_TTL, function() {
                return JWTAuth::parseToken()->getPayload();
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCachedUserOrganization(User $user, int $organizationId): ?Organization
    {
        $cacheKey = "user_org_access_{$user->id}_{$organizationId}";
        
        return cache()->remember($cacheKey, self::ORG_ACCESS_CACHE_TTL, function() use ($user, $organizationId) {
            return $user->organizations()->find($organizationId);
        });
    }

    public function invalidateUserOrganizationCache(int $userId, ?int $organizationId = null): void
    {
        if ($organizationId) {
            $cacheKey = "user_org_access_{$userId}_{$organizationId}";
            cache()->forget($cacheKey);
        } else {
            // Удаляем все кэши для пользователя (когда меняются его роли/организации)
            $pattern = "user_org_access_{$userId}_*";
            $this->forgetByPattern($pattern);
        }
    }

    public function invalidateJwtCache(string $token): void
    {
        $tokenKey = 'jwt_payload_' . hash('sha256', $token);
        cache()->forget($tokenKey);
    }

    private function forgetByPattern(string $pattern): void
    {
        // Для Redis можно использовать SCAN или KEYS
        if (config('cache.default') === 'redis') {
            $redis = Cache::getRedis();
            $keys = $redis->keys($pattern);
            if (!empty($keys)) {
                $redis->del($keys);
            }
        }
    }
}
