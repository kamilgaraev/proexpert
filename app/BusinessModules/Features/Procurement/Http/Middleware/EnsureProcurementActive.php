<?php

namespace App\BusinessModules\Features\Procurement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware для проверки активации модуля "Управление закупками"
 * Проверяет активацию модулей procurement и basic-warehouse
 */
class EnsureProcurementActive
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

        // Проверяем активацию модуля procurement
        if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
            return response()->json([
                'success' => false,
                'error' => 'Модуль "Управление закупками" не активирован для вашей организации',
                'module' => 'procurement',
                'error_code' => 'MODULE_NOT_ACTIVE',
            ], 403);
        }

        // Проверяем активацию модуля basic-warehouse (зависимость)
        if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            return response()->json([
                'success' => false,
                'error' => 'Модуль "Базовое управление складом" не активирован. Он необходим для работы модуля закупок.',
                'module' => 'basic-warehouse',
                'error_code' => 'DEPENDENCY_NOT_ACTIVE',
            ], 403);
        }

        return $next($request);
    }
}

