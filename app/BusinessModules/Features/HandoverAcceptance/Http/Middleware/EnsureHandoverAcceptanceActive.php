<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureHandoverAcceptanceActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) (
            $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
            ?? 0
        );

        if ($organizationId <= 0 || !app(AccessController::class)->hasModuleAccess($organizationId, 'handover-acceptance')) {
            return AdminResponse::error(trans_message('handover_acceptance.errors.module_inactive'), 403);
        }

        return $next($request);
    }
}
