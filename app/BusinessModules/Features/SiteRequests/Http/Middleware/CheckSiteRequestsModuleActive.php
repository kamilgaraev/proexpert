<?php

namespace App\BusinessModules\Features\SiteRequests\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class CheckSiteRequestsModuleActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if (!$organizationId && auth()->check()) {
            $organizationId = auth()->user()->current_organization_id;

            if ($organizationId) {
                $request->attributes->add(['current_organization_id' => $organizationId]);
            }
        }

        if (!$organizationId) {
            return $this->errorResponse(
                $request,
                trans_message('site_requests.organization_missing'),
                403
            );
        }

        $accessController = app(\App\Modules\Core\AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'site-requests')) {
            return $this->errorResponse(
                $request,
                trans_message('site_requests.module_not_active'),
                403,
                ['module' => 'site-requests']
            );
        }

        return $next($request);
    }

    private function errorResponse(Request $request, string $message, int $code, array $extra = []): Response
    {
        if ($this->isMobileRequest($request)) {
            return MobileResponse::error($message, $code, null, $extra);
        }

        return AdminResponse::error($message, $code, null, $extra);
    }

    private function isMobileRequest(Request $request): bool
    {
        $routeName = (string) optional($request->route())->getName();
        $path = trim($request->path(), '/');

        return str_starts_with($routeName, 'mobile.site_requests.')
            || str_contains($path, 'api/v1/mobile/site-requests');
    }
}
