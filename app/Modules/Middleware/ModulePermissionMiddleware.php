<?php

namespace App\Modules\Middleware;

use App\Http\Responses\AdminResponse;
use App\Modules\Services\ModulePermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class ModulePermissionMiddleware
{
    protected ModulePermissionService $permissionService;

    public function __construct(ModulePermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = Auth::user();

        if (! $user) {
            return AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.unauthenticated'),
            ], 401);
        }

        if (! $this->permissionService->userHasPermission($user, $permission)) {
            $permissionDetails = $this->permissionService->getPermissionDetails($permission);

            return AdminResponse::fromPayload([
                'success' => false,
                'message' => trans_message('errors.forbidden'),
                'required_permission' => $permission,
                'available_in_modules' => $permissionDetails['provided_by_modules'],
                'error_code' => 'PERMISSION_DENIED',
            ], 403);
        }

        return $next($request);
    }
}
