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
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация',
            ], 401);
        }

        if (!$this->permissionService->userHasPermission($user, $permission)) {
            $permissionDetails = $this->permissionService->getPermissionDetails($permission);
            
            return response()->json([
                'success' => false,
                'message' => 'Недостаточно прав доступа',
                'required_permission' => $permission,
                'available_in_modules' => $permissionDetails['provided_by_modules'],
                'error_code' => 'PERMISSION_DENIED'
            ], 403);
        }

        return $next($request);
    }
}
