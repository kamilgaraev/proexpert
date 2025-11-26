<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для проверки активации модуля "Заявки с объекта"
 */
class CheckSiteRequestsModuleActive
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'error' => 'Организация не определена',
            ], 403);
        }

        $accessController = app(\App\Modules\Core\AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'site-requests')) {
            return response()->json([
                'success' => false,
                'error' => 'Модуль "Заявки с объекта" не активирован для вашей организации',
                'module' => 'site-requests',
            ], 403);
        }

        return $next($request);
    }
}

