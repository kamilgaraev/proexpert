<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDesignManagementActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if ($organizationId <= 0) {
            return AdminResponse::error(trans_message('design_management.errors.organization_missing'), 403);
        }

        $accessController = app(AccessController::class);

        foreach (['design-management', 'project-management', 'file-management'] as $moduleSlug) {
            if (!$accessController->hasModuleAccess($organizationId, $moduleSlug)) {
                return AdminResponse::error(
                    trans_message('design_management.errors.module_not_active'),
                    403,
                    null,
                    ['module' => $moduleSlug]
                );
            }
        }

        return $next($request);
    }
}
