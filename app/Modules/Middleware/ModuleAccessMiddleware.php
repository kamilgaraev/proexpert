<?php

namespace App\Modules\Middleware;

use Closure;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Modules\Services\ModulePermissionService;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

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
            return AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.unauthenticated'),
            ], 401);
        }

        if (!$this->permissionService->userHasModuleAccess($user, $moduleSlug)) {
            return AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.forbidden'),
                'required_module' => $moduleSlug,
                'error_code' => 'MODULE_ACCESS_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
