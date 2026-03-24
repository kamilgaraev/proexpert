<?php

namespace App\BusinessModules\Features\BudgetEstimates\Http\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class EnsureBudgetEstimatesActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId) {
            Log::warning('budget_estimates.organization_context_missing', [
                'url' => $request->fullUrl(),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(trans_message('budget_estimates.organization_context_missing'), 400);
        }

        $accessController = app(\App\Modules\Core\AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'budget-estimates')) {
            Log::warning('budget_estimates.module.not_active', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'route' => $request->path(),
            ]);

            return AdminResponse::error(
                trans_message('budget_estimates.module_not_active'),
                403,
                null,
                ['module' => 'budget-estimates']
            );
        }

        return $next($request);
    }
}
