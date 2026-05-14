<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureExecutiveDocumentationActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if ($organizationId <= 0) {
            return AdminResponse::error(trans_message('executive_documentation.errors.organization_missing'), 403);
        }

        $accessController = app(AccessController::class);
        foreach (['executive-documentation', 'project-management', 'contract-management', 'file-management', 'report-templates'] as $moduleSlug) {
            if (!$accessController->hasModuleAccess($organizationId, $moduleSlug)) {
                return AdminResponse::error(
                    trans_message('executive_documentation.errors.module_not_active'),
                    403,
                    null,
                    ['module' => $moduleSlug]
                );
            }
        }

        return $next($request);
    }
}
