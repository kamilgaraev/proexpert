<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use App\Modules\Core\AccessController;
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

            return $this->errorResponse(
                $request,
                trans_message('budget_estimates.organization_context_missing'),
                400
            );
        }

        $accessController = app(AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'budget-estimates')) {
            Log::warning('budget_estimates.module.not_active', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'route' => $request->path(),
            ]);

            return $this->errorResponse(
                $request,
                trans_message('budget_estimates.module_not_active'),
                403,
                ['module' => 'budget-estimates']
            );
        }

        return $next($request);
    }

    private function errorResponse(Request $request, string $message, int $code, array $extra = []): Response
    {
        if (str_starts_with($request->path(), 'api/v1/mobile/')) {
            return MobileResponse::error($message, $code, null, $extra);
        }

        return AdminResponse::error($message, $code, null, $extra);
    }
}
