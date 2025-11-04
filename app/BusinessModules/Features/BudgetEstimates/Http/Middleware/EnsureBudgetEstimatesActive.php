<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureBudgetEstimatesActive
{
    /**
     * Проверка активации модуля "Сметное дело" для текущей организации
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'error' => 'Контекст организации не установлен',
            ], 400);
        }

        // Проверка активации модуля через AccessController
        $accessController = app(\App\Modules\Core\AccessController::class);
        
        if (!$accessController->hasModuleAccess($organizationId, 'budget-estimates')) {
            Log::warning('budget_estimates.module.not_active', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'route' => $request->path(),
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Модуль "Сметное дело" не активирован для вашей организации',
                'module' => 'budget-estimates',
            ], 403);
        }

        return $next($request);
    }
}

