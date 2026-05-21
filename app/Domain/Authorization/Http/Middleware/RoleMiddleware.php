<?php

namespace App\Domain\Authorization\Http\Middleware;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Models\AuthorizationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseAlias;

/**
 * Middleware РґР»СЏ РїСЂРѕРІРµСЂРєРё СЂРѕР»РµР№ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
 * 
 * РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ:
 * Route::middleware('role:organization_admin')->get('/admin', ...);
 * Route::middleware('role:project_manager,organization')->get('/projects', ...);
 * Route::middleware('role:foreman|worker,project,project_id')->get('/mobile', ...);
 */
class RoleMiddleware
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
     * @param string $roles Р РѕР»Рё (СЂР°Р·РґРµР»РµРЅРЅС‹Рµ | РґР»СЏ OR, , РґР»СЏ AND)
     * @param string|null $contextType РўРёРї РєРѕРЅС‚РµРєСЃС‚Р°
     * @param string|null $contextParam РџР°СЂР°РјРµС‚СЂ СЂРѕСѓС‚Р° РґР»СЏ РєРѕРЅС‚РµРєСЃС‚Р°
     * @return ResponseAlias
     */
    public function handle(Request $request, Closure $next, string $roles, ?string $contextType = null, ?string $contextParam = null): ResponseAlias
    {
        // РџСЂРѕРїСѓСЃРєР°РµРј Prometheus РјРѕРЅРёС‚РѕСЂРёРЅРі Р±РµР· РїСЂРѕРІРµСЂРєРё СЂРѕР»РµР№
        $userAgent = $request->userAgent() ?? '';
        if (str_contains($userAgent, 'Prometheus')) {
            return $next($request);
        }
        
        $user = $request->user();
        
        if (!$user) {
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => 'Unauthorized'], 401);
        }

        // РћРїСЂРµРґРµР»СЏРµРј РєРѕРЅС‚РµРєСЃС‚
        $contextId = $this->resolveContextId($request, $contextType, $contextParam);
        
        // РџСЂРѕРІРµСЂСЏРµРј СЂРѕР»Рё
        if (!$this->checkRoles($user, $roles, $contextId)) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'error' => 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РїСЂР°РІ РґРѕСЃС‚СѓРїР°',
                'required_roles' => $roles,
                'context_type' => $contextType,
                'context_id' => $contextId
            ], 403);
        }

        return $next($request);
    }

    /**
     * РџСЂРѕРІРµСЂРёС‚СЊ СЂРѕР»Рё РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
     */
    protected function checkRoles($user, string $roles, ?int $contextId): bool
    {
        // Р Р°Р·Р±РёСЂР°РµРј СЂРѕР»Рё РїРѕ | (OR Р»РѕРіРёРєР°)
        $roleGroups = explode('|', $roles);
        
        foreach ($roleGroups as $roleGroup) {
            // Р Р°Р·Р±РёСЂР°РµРј СЂРѕР»Рё РїРѕ , (AND Р»РѕРіРёРєР°)
            $requiredRoles = array_map('trim', explode(',', $roleGroup));
            
            if ($this->hasAllRoles($user, $requiredRoles, $contextId)) {
                return true; // РћРґРЅР° РёР· РіСЂСѓРїРї СЂРѕР»РµР№ РїРѕРґРѕС€Р»Р°
            }
        }
        
        return false;
    }

    /**
     * РџСЂРѕРІРµСЂРёС‚СЊ, РµСЃС‚СЊ Р»Рё Сѓ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ РІСЃРµ СЂРѕР»Рё РёР· СЃРїРёСЃРєР°
     */
    protected function hasAllRoles($user, array $roles, ?int $contextId): bool
    {
        foreach ($roles as $role) {
            if (!$this->authService->hasRole($user, trim($role), $contextId)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * РћРїСЂРµРґРµР»РёС‚СЊ ID РєРѕРЅС‚РµРєСЃС‚Р° РёР· Р·Р°РїСЂРѕСЃР°
     */
    protected function resolveContextId(Request $request, ?string $contextType, ?string $contextParam): ?int
    {
        if (!$contextType) {
            return null;
        }

        switch ($contextType) {
            case 'system':
                return AuthorizationContext::getSystemContext()->id;
                
            case 'organization':
                $organizationId = $this->extractParam($request, $contextParam ?? 'organization_id');
                if (!$organizationId && $request->user()) {
                    $organizationId = $request->user()->current_organization_id;
                }
                return $organizationId ? AuthorizationContext::getOrganizationContext($organizationId)->id : null;
                
            case 'project':
                $projectId = $this->extractParam($request, $contextParam ?? 'project_id');
                if ($projectId) {
                    // РџРѕР»СѓС‡Р°РµРј organization_id РїСЂРѕРµРєС‚Р°
                    $project = \App\Models\Project::find($projectId);
                    $organizationId = $project ? $project->organization_id : null;
                    
                    if ($organizationId) {
                        return AuthorizationContext::getProjectContext($projectId, $organizationId)->id;
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
}
