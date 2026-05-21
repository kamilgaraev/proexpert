<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class RequestDedupMiddleware
{
    private const DEDUP_TTL = 5; // 5 СЃРµРєСѓРЅРґ

    public function handle(Request $request, Closure $next): Response
    {
        // РџСЂРёРјРµРЅСЏРµРј РґРµРґСѓРїР»РёРєР°С†РёСЋ С‚РѕР»СЊРєРѕ РґР»СЏ РѕРїСЂРµРґРµР»РµРЅРЅС‹С… РјР°СЂС€СЂСѓС‚РѕРІ
        $shouldDeduplicate = $this->shouldDeduplicate($request);
        
        if (!$shouldDeduplicate) {
            return $next($request);
        }

        $requestKey = $this->generateRequestKey($request);
        $lockKey = "req_lock_{$requestKey}";
        $cacheKey = "req_cache_{$requestKey}";

        // РџСЂРѕРІРµСЂСЏРµРј, РµСЃС‚СЊ Р»Рё РєСЌС€РёСЂРѕРІР°РЅРЅС‹Р№ СЂРµР·СѓР»СЊС‚Р°С‚
        $cachedResponse = Cache::get($cacheKey);
        if ($cachedResponse !== null) {
            return \App\Http\Responses\AdminResponse::fromPayload($cachedResponse['data'], $cachedResponse['status'])
                ->withHeaders($cachedResponse['headers']);
        }

        // Р‘Р»РѕРєРёСЂСѓРµРј РїРѕРІС‚РѕСЂРЅС‹Рµ Р·Р°РїСЂРѕСЃС‹
        if (Cache::has($lockKey)) {
            return \App\Http\Responses\AdminResponse::fromPayload(['message' => 'Duplicate request ignored'], 429);
        }

        // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј Р±Р»РѕРєРёСЂРѕРІРєСѓ
        Cache::put($lockKey, true, self::DEDUP_TTL);

        try {
            $response = $next($request);
            
            // РљСЌС€РёСЂСѓРµРј СѓСЃРїРµС€РЅС‹Р№ РѕС‚РІРµС‚
            if ($response->getStatusCode() < 400) {
                $responseData = [
                    'data' => json_decode($response->getContent(), true),
                    'status' => $response->getStatusCode(),
                    'headers' => $response->headers->all()
                ];
                Cache::put($cacheKey, $responseData, self::DEDUP_TTL);
            }

            return $response;
        } finally {
            // РЈР±РёСЂР°РµРј Р±Р»РѕРєРёСЂРѕРІРєСѓ
            Cache::forget($lockKey);
        }
    }

    private function shouldDeduplicate(Request $request): bool
    {
        // Р”РµРґСѓРїР»РёС†РёСЂСѓРµРј С‚РѕР»СЊРєРѕ GET Р·Р°РїСЂРѕСЃС‹ РґР»СЏ РѕРїСЂРµРґРµР»РµРЅРЅС‹С… СЌРЅРґРїРѕРёРЅС‚РѕРІ
        if (!$request->isMethod('GET')) {
            return false;
        }

        $dedupRoutes = [
            'api/v1/landing/auth/me',
            'api/v1/landing/dashboard',
            'api/v1/landing/modules/active',
            'api/v1/landing/modules/expiring',
            'api/v1/landing/billing/balance',
            'api/lk/v1/permissions'
        ];

        foreach ($dedupRoutes as $route) {
            if (str_contains($request->getPathInfo(), $route)) {
                return true;
            }
        }

        return false;
    }

    private function generateRequestKey(Request $request): string
    {
        $key = $request->getPathInfo() . '_' . $request->getQueryString();
        
        // Р”РѕР±Р°РІР»СЏРµРј ID РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РµСЃР»Рё Р°СѓС‚РµРЅС‚РёС„РёС†РёСЂРѕРІР°РЅ
        if ($user = $request->user()) {
            $key .= '_user_' . $user->id;
        }

        // Р”РѕР±Р°РІР»СЏРµРј organization_id РёР· Р·Р°РіРѕР»РѕРІРєРѕРІ РёР»Рё Р°С‚СЂРёР±СѓС‚РѕРІ
        $orgId = $request->attributes->get('current_organization_id') ?? 
                 $request->header('X-Organization-Id');
        
        if ($orgId) {
            $key .= '_org_' . $orgId;
        }

        return hash('sha256', $key);
    }
}
