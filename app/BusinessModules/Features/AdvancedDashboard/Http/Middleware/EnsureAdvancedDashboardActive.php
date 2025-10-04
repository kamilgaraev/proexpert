<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Modules\Core\AccessController;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdvancedDashboardActive
{
    protected AccessController $accessController;

    public function __construct(AccessController $accessController)
    {
        $this->accessController = $accessController;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
                'code' => 'UNAUTHORIZED'
            ], 401);
        }

        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Organization context required',
                'code' => 'NO_ORGANIZATION_CONTEXT'
            ], 400);
        }

        // Проверяем, что модуль advanced-dashboard активен
        if (!$this->accessController->hasModuleAccess($organizationId, 'advanced-dashboard')) {
            return response()->json([
                'success' => false,
                'message' => 'Advanced Dashboard module is not active for this organization',
                'code' => 'MODULE_NOT_ACTIVE',
                'required_module' => 'advanced-dashboard',
                'hint' => 'Activate the Advanced Dashboard module to access this feature'
            ], 403);
        }

        return $next($request);
    }
}

