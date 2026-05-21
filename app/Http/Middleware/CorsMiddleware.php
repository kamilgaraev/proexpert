<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Services\Logging\LoggingService;

class CorsMiddleware
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }
    /**
     * РћР±СЂР°Р±Р°С‚С‹РІР°РµС‚ РІС…РѕРґСЏС‰РёР№ Р·Р°РїСЂРѕСЃ.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // РџРѕР»СѓС‡Р°РµРј Origin РёР· Р·Р°РіРѕР»РѕРІРєР° Р·Р°РїСЂРѕСЃР°
        $origin = $request->header('Origin');
        
        // Р›РѕРіРёСЂСѓРµРј С‚РѕР»СЊРєРѕ РїРѕРґРѕР·СЂРёС‚РµР»СЊРЅС‹Рµ РёР»Рё РІР°Р¶РЅС‹Рµ CORS Р·Р°РїСЂРѕСЃС‹ (РЅРµ /metrics РѕС‚ Prometheus)
        if (!$this->isRoutineRequest($request)) {
            $this->logging->access([
                'event' => 'cors.request.processed',
                'method' => $request->method(),
                'origin' => $origin,
                'uri' => $request->getRequestUri(),
                'user_agent' => $request->header('User-Agent'),
                'ip_address' => $request->ip()
            ]);
        }
        
        // РџРѕР»СѓС‡Р°РµРј РєРѕРЅС„РёРіСѓСЂР°С†РёСЋ CORS
        $allowedOrigins = Config::get('cors.allowed_origins', []);
        $allowedOriginsPatterns = Config::get('cors.allowed_origins_patterns', []);
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        $allowAnyOriginInDev = Config::get('cors.allow_any_origin_in_dev', false);
        
        // РћРїСЂРµРґРµР»СЏРµРј, РґРѕСЃС‚СѓРїРµРЅ Р»Рё Р·Р°РїСЂРѕС€РµРЅРЅС‹Р№ origin
        $allowedOrigin = null;
        $allowCredentials = 'false';
        $originMatched = false;
        
        // Р•СЃР»Рё РјС‹ РІ СЂРµР¶РёРјРµ СЂР°Р·СЂР°Р±РѕС‚РєРё Рё РЅР°СЃС‚СЂРѕР№РєР° СЂР°Р·СЂРµС€Р°РµС‚ Р»СЋР±РѕР№ origin
        if (app()->environment('local') && $allowAnyOriginInDev) {
            $allowedOrigin = $origin ?: '*';
            $allowCredentials = ($allowedOrigin === '*') ? 'false' : 'true';
            $originMatched = true;
        } 
        // РРЅР°С‡Рµ РїСЂРѕРІРµСЂСЏРµРј РїРѕ СЃРїРёСЃРєСѓ СЂР°Р·СЂРµС€РµРЅРЅС‹С…
        else if ($origin) {
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
                $allowCredentials = 'true';
                $originMatched = true;
            } else {
                foreach ($allowedOriginsPatterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                        break;
                    }
                }
            }
            
            if (!$originMatched) {
                // Р’ СЂРµР¶РёРјРµ СЂР°Р·СЂР°Р±РѕС‚РєРё РјРѕР¶РµРј Р±С‹С‚СЊ Р±РѕР»РµРµ СЃРЅРёСЃС…РѕРґРёС‚РµР»СЊРЅС‹РјРё
                if (app()->environment('local')) {
                    // SECURITY: Р Р°Р·СЂРµС€РµРЅРёРµ РЅРµРёР·РІРµСЃС‚РЅРѕРіРѕ origin РІ dev СЃСЂРµРґРµ
                    $this->logging->security('cors.origin.allowed.dev', [
                        'origin' => $origin,
                        'environment' => 'local',
                        'uri' => $request->getRequestUri()
                    ], 'info');
                    $allowedOrigin = $origin;
                    $allowCredentials = 'true';
                    $originMatched = true;
                } else {
                    // Р’ РїСЂРѕРґР°РєС€РµРЅРµ РґР»СЏ prohelper.pro РґРѕРјРµРЅРѕРІ СЂР°Р·СЂРµС€Р°РµРј
                    if ($origin && (strpos($origin, '.prohelper.pro') !== false || $origin === 'https://prohelper.pro')) {
                        // SECURITY: Р Р°Р·СЂРµС€РµРЅРёРµ prohelper.pro РґРѕРјРµРЅР° РЅРµ РёР· СЃРїРёСЃРєР°
                        $this->logging->security('cors.origin.allowed.prohelper', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'auto_approved' => true
                        ], 'info');
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                    } else {
                        // SECURITY: РљР РРўРР§РќРћ - РћС‚РєР»РѕРЅРµРЅ Р·Р°РїСЂРѕСЃ СЃ РЅРµРґРѕРїСѓСЃС‚РёРјРѕРіРѕ origin
                        $this->logging->security('cors.origin.rejected', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'user_agent' => $request->header('User-Agent'),
                            'ip_address' => $request->ip(),
                            'allowed_origins' => $allowedOrigins,
                            'potential_security_threat' => true
                        ], 'warning');
                        $allowedOrigin = 'null';
                        $allowCredentials = 'false';
                    }
                }
            }
        } else {
            // Р•СЃР»Рё origin РЅРµ СѓРєР°Р·Р°РЅ, РёСЃРїРѕР»СЊР·СѓРµРј wildcard (С‚РѕР»СЊРєРѕ РґР»СЏ Р·Р°РїСЂРѕСЃРѕРІ Р±РµР· credentials)
            $allowedOrigin = '*';
            $allowCredentials = 'false';
            $originMatched = true;
        }
        
        // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј Р·Р°РіРѕР»РѕРІРєРё CORS РґР»СЏ РѕС‚РІРµС‚Р°
        $headers = [
            // РЈСЃС‚Р°РЅР°РІР»РёРІР°РµРј origin РёР· Р·Р°РїСЂРѕСЃР° РёР»Рё wildcard
            'Access-Control-Allow-Origin' => $allowedOrigin,
            // Р Р°Р·СЂРµС€РёС‚СЊ РІРєР»СЋС‡Р°С‚СЊ СѓС‡РµС‚РЅС‹Рµ РґР°РЅРЅС‹Рµ (С‚РѕР»СЊРєРѕ РµСЃР»Рё РЅРµ wildcard)
            'Access-Control-Allow-Credentials' => $allowCredentials,
            // Р Р°Р·СЂРµС€РёС‚СЊ СѓРєР°Р·Р°РЅРЅС‹Рµ РјРµС‚РѕРґС‹
            'Access-Control-Allow-Methods' => implode(', ', $allowedMethods),
            // Р Р°Р·СЂРµС€РёС‚СЊ СѓРєР°Р·Р°РЅРЅС‹Рµ Р·Р°РіРѕР»РѕРІРєРё
            'Access-Control-Allow-Headers' => implode(', ', $allowedHeaders),
            // РЎСЂРѕРє РґРµР№СЃС‚РІРёСЏ preflight Р·Р°РїСЂРѕСЃР°
            'Access-Control-Max-Age' => (string) $maxAge,
        ];
        
        // Р”РѕР±Р°РІР»СЏРµРј exposed headers, РµСЃР»Рё РѕРЅРё РµСЃС‚СЊ
        if (!empty($exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }
        
        // Р•СЃР»Рё СЌС‚Рѕ preflight OPTIONS-Р·Р°РїСЂРѕСЃ
        if ($request->isMethod('OPTIONS')) {
            // TECHNICAL: РћР±СЂР°Р±РѕС‚РєР° preflight Р·Р°РїСЂРѕСЃР° - РІР°Р¶РЅРѕ РґР»СЏ API РёРЅС‚РµРіСЂР°С†РёР№
            $this->logging->technical('cors.preflight.processed', [
                'origin' => $origin,
                'allowed_origin' => $allowedOrigin,
                'origin_matched' => $originMatched,
                'uri' => $request->getRequestUri(),
                'requested_method' => $request->header('Access-Control-Request-Method'),
                'requested_headers' => $request->header('Access-Control-Request-Headers')
            ]);
            // Р’РѕР·РІСЂР°С‰Р°РµРј РїСѓСЃС‚РѕР№ РѕС‚РІРµС‚ 200 СЃ РЅСѓР¶РЅС‹РјРё CORS-Р·Р°РіРѕР»РѕРІРєР°РјРё
            return response('', 200, $headers);
        }
        
        try {
            // Р”Р»СЏ РґСЂСѓРіРёС… Р·Р°РїСЂРѕСЃРѕРІ РІС‹Р·С‹РІР°РµРј СЃР»РµРґСѓСЋС‰РёР№ middleware РІ С†РµРїРѕС‡РєРµ
            $response = $next($request);
            
            // Р”РѕР±Р°РІР»СЏРµРј Р·Р°РіРѕР»РѕРІРєРё CORS Рє РѕС‚РІРµС‚Сѓ
            foreach ($headers as $key => $value) {
                $response->headers->set($key, $value);
            }
            
            // Р›РѕРіРёСЂСѓРµРј С‚РѕР»СЊРєРѕ РїСЂРѕР±Р»РµРјРЅС‹Рµ РёР»Рё РІР°Р¶РЅС‹Рµ CORS РѕС‚РІРµС‚С‹ (РЅРµ РєР°Р¶РґС‹Р№ /metrics)
            if (!$this->isRoutineRequest($request) || $response->getStatusCode() >= 400) {
                // ACCESS: РЈСЃРїРµС€РЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° CORS
                $this->logging->access([
                    'event' => 'cors.response.success',
                    'uri' => $request->getRequestUri(),
                    'method' => $request->method(),
                    'status_code' => $response->getStatusCode(),
                    'allow_origin' => $response->headers->get('Access-Control-Allow-Origin'),
                    'origin' => $origin
                ]);
            }
            
            return $response;
        } catch (\Throwable $e) {
            // TECHNICAL: РСЃРєР»СЋС‡РµРЅРёРµ РІ CORS middleware
            $this->logging->technical('cors.exception.caught', [
                'uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'origin' => $origin
            ], 'error');

            // РЎРїРµС†РёР°Р»СЊРЅР°СЏ РѕР±СЂР°Р±РѕС‚РєР° РґР»СЏ business logic РёСЃРєР»СЋС‡РµРЅРёР№ - РїСЂРѕР±СЂР°СЃС‹РІР°РµРј РґР°Р»СЊС€Рµ РІ Handler
            if ($e instanceof \App\Exceptions\Billing\InsufficientBalanceException ||
                $e instanceof \App\Exceptions\BusinessLogicException ||
                $e instanceof \Illuminate\Validation\ValidationException ||
                $e instanceof \Illuminate\Auth\AuthenticationException ||
                $e instanceof \Illuminate\Auth\Access\AuthorizationException ||
                $e instanceof \InvalidArgumentException) { // Р”Р»СЏ РѕС€РёР±РѕРє РєРѕРЅС„РёРіСѓСЂР°С†РёРё (РЅР°РїСЂРёРјРµСЂ, guard РЅРµ РѕРїСЂРµРґРµР»С‘РЅ)
                
                // РЎРѕС…СЂР°РЅСЏРµРј CORS Р·Р°РіРѕР»РѕРІРєРё РІ Р·Р°РїСЂРѕСЃРµ РґР»СЏ Handler
                $request->attributes->set('cors_headers', $headers);
                
                throw $e; // РџСЂРѕР±СЂР°СЃС‹РІР°РµРј РІ Handler
            }

            // TECHNICAL: РЎРёСЃС‚РµРјРЅР°СЏ РѕС€РёР±РєР° РІ CORS middleware
            $this->logging->technical('cors.system.error', [
                'error_message' => $e->getMessage(),
                'uri' => $request->getRequestUri(),
                'method' => $request->method(),
                'exception_class' => get_class($e),
                'stack_trace_hash' => md5($e->getTraceAsString())
            ], 'error');
            
            // Р’РѕР·РІСЂР°С‰Р°РµРј РѕС‚РІРµС‚ РѕР± РѕС€РёР±РєРµ СЃ Р·Р°РіРѕР»РѕРІРєР°РјРё CORS С‚РѕР»СЊРєРѕ РґР»СЏ СЃРёСЃС‚РµРјРЅС‹С… РѕС€РёР±РѕРє
            return \App\Http\Responses\AdminResponse::fromPayload([
                'error' => 'РћС€РёР±РєР° РЅР° СЃРµСЂРІРµСЂРµ',
                'message' => 'РџСЂРё РѕР±СЂР°Р±РѕС‚РєРµ Р·Р°РїСЂРѕСЃР° РїСЂРѕРёР·РѕС€Р»Р° РѕС€РёР±РєР°. РђРґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂ СѓРІРµРґРѕРјР»РµРЅ. [Diag: Catch Block Reached]'
            ], 500, $headers);
        }
    }

    /**
     * РџСЂРѕРІРµСЂСЏРµС‚ СЏРІР»СЏРµС‚СЃСЏ Р»Рё Р·Р°РїСЂРѕСЃ СЂСѓС‚РёРЅРЅС‹Рј (РЅР°РїСЂРёРјРµСЂ, РјРѕРЅРёС‚РѕСЂРёРЅРі)
     */
    protected function isRoutineRequest(Request $request): bool
    {
        $uri = $request->getRequestUri();
        $userAgent = $request->header('User-Agent', '');
        
        // Prometheus РјРѕРЅРёС‚РѕСЂРёРЅРі
        if (str_contains($uri, '/metrics') && str_contains($userAgent, 'Prometheus/')) {
            return true;
        }
        
        // Health checks
        if (in_array($uri, ['/up', '/health', '/ping'])) {
            return true;
        }
        
        return false;
    }
}
