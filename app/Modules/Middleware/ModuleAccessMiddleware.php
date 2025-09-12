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
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация',
            ], 401);
        }

        if (!$this->permissionService->userHasModuleAccess($user, $moduleSlug)) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ к модулю запрещен',
                'required_module' => $moduleSlug,
                'error_code' => 'MODULE_ACCESS_DENIED'
            ], 403);
        }

        return $next($request);
    }
}
