<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Middleware РґР»СЏ РїСЂРѕРІРµСЂРєРё РґРѕСЃС‚СѓРїР° Рє РёРЅС‚РµСЂС„РµР№СЃР°Рј
 * 
 * РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ:
 * Route::middleware('interface:lk')->group(function () { ... });
 * Route::middleware('interface:admin,organization')->get('/admin', ...);
 * Route::middleware('interface:mobile,project,project_id')->get('/mobile', ...);
 */
class InterfaceMiddleware
{
    protected AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @param string $interface РРЅС‚РµСЂС„РµР№СЃ (lk, admin, mobile)
     * @param string|null $contextType РўРёРї РєРѕРЅС‚РµРєСЃС‚Р°
     * @param string|null $contextParam РџР°СЂР°РјРµС‚СЂ СЂРѕСѓС‚Р° РґР»СЏ РєРѕРЅС‚РµРєСЃС‚Р°
     * @return ResponseAlias
     */
    public function handle(Request $request, Closure $next, string $interface, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        // РџСЂРѕРїСѓСЃРєР°РµРј Prometheus РјРѕРЅРёС‚РѕСЂРёРЅРі Р±РµР· РїСЂРѕРІРµСЂРєРё РёРЅС‚РµСЂС„РµР№СЃР°
        $userAgent = $request->userAgent() ?? '';
        if (str_contains($userAgent, 'Prometheus')) {
            return $next($request);
        }
        
        $user = $request->user();
        
        if (!$user) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => 'Unauthorized'], 401);
        }

        // РћРїСЂРµРґРµР»СЏРµРј РєРѕРЅС‚РµРєСЃС‚
        $context = $this->resolveContext($request, $contextType, $contextParam);
        
        // РџСЂРѕРІРµСЂСЏРµРј РґРѕСЃС‚СѓРї Рє РёРЅС‚РµСЂС„РµР№СЃСѓ
        $hasAccess = $this->authService->canAccessInterface($user, $interface, $context);
        
        if (!$hasAccess) {
            // Р”РёР°РіРЅРѕСЃС‚РёРєР° РґР»СЏ РѕС‚Р»Р°РґРєРё РїСЂРѕР±Р»РµРј СЃ РґРѕСЃС‚СѓРїРѕРј Рє РёРЅС‚РµСЂС„РµР№СЃР°Рј
            \Log::warning('interface.access.denied', [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'interface' => $interface,
                'context_type' => $contextType,
                'context_id' => $context?->id,
                'context_resource_id' => $context?->resource_id,
                'current_organization_id' => $request->attributes->get('current_organization_id'),
                'user_current_org' => $user->current_organization_id,
                'uri' => $request->getRequestUri(),
            ]);
            
            return \App\Http\Responses\AdminResponse::fromPayload([
                'error' => 'Р”РѕСЃС‚СѓРї Рє РёРЅС‚РµСЂС„РµР№СЃСѓ Р·Р°РїСЂРµС‰РµРЅ',
                'interface' => $interface,
                'message' => $this->getInterfaceMessage($interface)
            ], 403);
        }

        // Р”РѕР±Р°РІР»СЏРµРј РёРЅС„РѕСЂРјР°С†РёСЋ РѕР± РёРЅС‚РµСЂС„РµР№СЃРµ РІ Р·Р°РїСЂРѕСЃ РґР»СЏ РґР°Р»СЊРЅРµР№С€РµРіРѕ РёСЃРїРѕР»СЊР·РѕРІР°РЅРёСЏ
        $request->merge(['current_interface' => $interface]);

        return $next($request);
    }

    /**
     * РћРїСЂРµРґРµР»РёС‚СЊ РєРѕРЅС‚РµРєСЃС‚ Р°РІС‚РѕСЂРёР·Р°С†РёРё
     */
    protected function resolveContext(Request $request, ?string $contextType, ?string $contextParam): ?AuthorizationContext
    {
        // Р•СЃР»Рё contextType РЅРµ СѓРєР°Р·Р°РЅ СЏРІРЅРѕ, РїСЂРѕР±СѓРµРј Р°РІС‚РѕРјР°С‚РёС‡РµСЃРєРё РѕРїСЂРµРґРµР»РёС‚СЊ РєРѕРЅС‚РµРєСЃС‚
        // РёР· СЂР°РЅРµРµ СѓСЃС‚Р°РЅРѕРІР»РµРЅРЅС‹С… Р°С‚СЂРёР±СѓС‚РѕРІ (middleware organization.context)
        if (!$contextType) {
            // РџСЂРѕР±СѓРµРј РїРѕР»СѓС‡РёС‚СЊ organization_id РёР· Р°С‚СЂРёР±СѓС‚РѕРІ Р·Р°РїСЂРѕСЃР°
            $organizationId = $request->attributes->get('current_organization_id');
            if ($organizationId) {
                return AuthorizationContext::getOrganizationContext($organizationId);
            }
            
            // Fallback РЅР° current_organization_id РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
            $user = $request->user();
            if ($user && $user->current_organization_id) {
                return AuthorizationContext::getOrganizationContext($user->current_organization_id);
            }
            
            return null;
        }

        switch ($contextType) {
            case 'system':
                return AuthorizationContext::getSystemContext();
                
            case 'organization':
                $organizationId = $this->extractParam($request, $contextParam ?? 'organization_id');
                if (!$organizationId) {
                    // РџСЂРѕР±СѓРµРј РїРѕР»СѓС‡РёС‚СЊ РёР· Р°С‚СЂРёР±СѓС‚РѕРІ Р·Р°РїСЂРѕСЃР°
                    $organizationId = $request->attributes->get('current_organization_id');
                }
                if (!$organizationId && $request->user()) {
                    $organizationId = $request->user()->current_organization_id;
                }
                return $organizationId ? AuthorizationContext::getOrganizationContext($organizationId) : null;
                
            case 'project':
                $projectId = $this->extractParam($request, $contextParam ?? 'project_id');
                if ($projectId) {
                    $project = \App\Models\Project::find($projectId);
                    $organizationId = $project ? $project->organization_id : null;
                    
                    if ($organizationId) {
                        return AuthorizationContext::getProjectContext($projectId, $organizationId);
                    }
                }
                return null;
                
            default:
                return null;
        }
    }

    /**
     * РР·РІР»РµС‡СЊ РїР°СЂР°РјРµС‚СЂ РёР· Р·Р°РїСЂРѕСЃР°
     */
    protected function extractParam(Request $request, string $param): mixed
    {
        return $request->route($param) 
            ?? $request->get($param) 
            ?? $request->input($param);
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ СЃРѕРѕР±С‰РµРЅРёРµ РѕР± РѕРіСЂР°РЅРёС‡РµРЅРёРё РґРѕСЃС‚СѓРїР° Рє РёРЅС‚РµСЂС„РµР№СЃСѓ
     */
    protected function getInterfaceMessage(string $interface): string
    {
        $messages = [
            'lk' => 'Р”РѕСЃС‚СѓРї РІ Р»РёС‡РЅС‹Р№ РєР°Р±РёРЅРµС‚ СЂР°Р·СЂРµС€РµРЅ С‚РѕР»СЊРєРѕ РІР»Р°РґРµР»СЊС†Р°Рј Рё Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°Рј РѕСЂРіР°РЅРёР·Р°С†РёР№',
            'admin' => 'Р”РѕСЃС‚СѓРї РІ Р°РґРјРёРЅРёСЃС‚СЂР°С‚РёРІРЅСѓСЋ РїР°РЅРµР»СЊ СЂР°Р·СЂРµС€РµРЅ С‚РѕР»СЊРєРѕ СЃРёСЃС‚РµРјРЅС‹Рј Р°РґРјРёРЅРёСЃС‚СЂР°С‚РѕСЂР°Рј Рё РјРѕРґРµСЂР°С‚РѕСЂР°Рј',
            'mobile' => 'Р”РѕСЃС‚СѓРї Рє РјРѕР±РёР»СЊРЅРѕРјСѓ РїСЂРёР»РѕР¶РµРЅРёСЋ СЂР°Р·СЂРµС€РµРЅ С‚РѕР»СЊРєРѕ СЂР°Р±РѕС‚РЅРёРєР°Рј РЅР° РѕР±СЉРµРєС‚Р°С…',
        ];

        return $messages[$interface] ?? 'Р”РѕСЃС‚СѓРї Рє РґР°РЅРЅРѕРјСѓ РёРЅС‚РµСЂС„РµР№СЃСѓ РѕРіСЂР°РЅРёС‡РµРЅ';
    }
}
