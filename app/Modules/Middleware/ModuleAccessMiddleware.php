<?php

namespace App\Modules\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Services\ModulePermissionService;
use Symfony\Component\HttpFoundation\Response;

class ModuleAccessMiddleware
{
    protected ModulePermissionService $permissionService;

    public function __construct(ModulePermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    public function handle(Request $request, Closure $next, string $moduleSlug): Response
    {
        $user = Auth::user();
        
        if (!$user) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РќРµРѕР±С…РѕРґРёРјР° Р°РІС‚РѕСЂРёР·Р°С†РёСЏ',
            ], 401);
        }

        if (!$this->permissionService->userHasModuleAccess($user, $moduleSlug)) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'Р”РѕСЃС‚СѓРї Рє РјРѕРґСѓР»СЋ Р·Р°РїСЂРµС‰РµРЅ',
                'required_module' => $moduleSlug,
                'error_code' => 'MODULE_ACCESS_DENIED'
            ], 403);
        }

        return $next($request);
    }
}
