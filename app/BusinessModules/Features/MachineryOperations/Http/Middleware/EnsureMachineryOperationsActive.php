<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMachineryOperationsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if ($organizationId <= 0) {
            return $this->errorResponse($request, trans_message('machinery_operations.errors.organization_missing'), 403);
        }

        $accessController = app(AccessController::class);

        foreach (['machinery-operations', 'project-management'] as $moduleSlug) {
            if (!$accessController->hasModuleAccess($organizationId, $moduleSlug)) {
                return $this->errorResponse($request, trans_message('machinery_operations.errors.module_not_active'), 403);
            }
        }

        return $next($request);
    }

    private function errorResponse(Request $request, string $message, int $code): Response
    {
        if (str_starts_with($request->path(), 'api/v1/mobile/')) {
            return MobileResponse::error($message, $code);
        }

        return AdminResponse::error($message, $code);
    }
}
