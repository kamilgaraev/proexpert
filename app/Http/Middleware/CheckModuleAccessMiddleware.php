<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\Landing\OrganizationModuleService;

class CheckModuleAccessMiddleware
{
    protected OrganizationModuleService $moduleService;

    public function __construct(OrganizationModuleService $moduleService)
    {
        $this->moduleService = $moduleService;
    }

    public function handle(Request $request, Closure $next, string $moduleSlug)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Необходима авторизация',
            ], 401);
        }

        $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;
        
        if (!$organizationId) {
            return response()->json([
                'success' => false,
                'message' => 'Организация не найдена',
            ], 404);
        }

        $hasAccess = $this->moduleService->hasModuleAccess($organizationId, $moduleSlug);

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Доступ к модулю запрещен',
                'required_module' => $moduleSlug,
            ], 403);
        }

        return $next($request);
    }
} 