<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\MobileResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureChangeManagementActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if ($organizationId <= 0) {
            return $this->errorResponse($request, trans_message('change_management.errors.organization_missing'), 403);
        }

        $accessController = app(AccessController::class);

        foreach (['change-management', 'project-management'] as $moduleSlug) {
            if (!$accessController->hasModuleAccess($organizationId, $moduleSlug)) {
                return $this->errorResponse($request, trans_message('change_management.errors.module_not_active'), 403);
            }
        }

        return $next($request);
    }

    private function errorResponse(Request $request, string $message, int $code): Response
    {
        $path = $request->path();

        if (str_starts_with($path, 'api/v1/mobile/')) {
            return MobileResponse::error($message, $code);
        }

        if (str_starts_with($path, 'api/v1/customer/')) {
            return CustomerResponse::error($message, $code);
        }

        return AdminResponse::error($message, $code);
    }
}
