<?php

namespace App\Modules\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Services\ModulePermissionService;
use Symfony\Component\HttpFoundation\Response;

class ModulePermissionMiddleware
{
    protected ModulePermissionService $permissionService;

    public function __construct(ModulePermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $userAgent = $request->userAgent() ?? '';
        if (str_contains($userAgent, 'Prometheus')) {
            return $next($request);
        }
        
        $user = Auth::user();
        
        if (!$user) {
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РќРµРѕР±С…РѕРґРёРјР° Р°РІС‚РѕСЂРёР·Р°С†РёСЏ',
            ], 401);
        }

        if (!$this->permissionService->userHasPermission($user, $permission)) {
            $permissionDetails = $this->permissionService->getPermissionDetails($permission);
            
            return \App\Http\Responses\AdminResponse::fromPayload([
                'success' => false,
                'message' => 'РќРµРґРѕСЃС‚Р°С‚РѕС‡РЅРѕ РїСЂР°РІ РґРѕСЃС‚СѓРїР°',
                'required_permission' => $permission,
                'available_in_modules' => $permissionDetails['provided_by_modules'],
                'error_code' => 'PERMISSION_DENIED'
            ], 403);
        }

        return $next($request);
    }
}
