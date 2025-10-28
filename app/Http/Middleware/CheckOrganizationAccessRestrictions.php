<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\OrganizationAccessRestriction;
use Illuminate\Support\Facades\Log;

class CheckOrganizationAccessRestrictions
{
    private array $actionMap = [
        'store' => 'create',
        'update' => 'edit',
        'destroy' => 'delete',
        'export' => 'bulk_export',
        'requestPayment' => 'request_payments',
        'createAct' => 'create_acts',
        'uploadDocument' => 'upload_documents',
        'editWork' => 'edit_works',
    ];

    public function handle(Request $request, Closure $next, ?string $requiredAction = null): Response
    {
        $user = $request->user();
        
        if (!$user) {
            return $next($request);
        }

        $organizationId = $request->attributes->get('current_organization_id') 
                       ?? $user->current_organization_id;

        if (!$organizationId) {
            return $next($request);
        }

        $restriction = OrganizationAccessRestriction::where('organization_id', $organizationId)
            ->active()
            ->first();

        if (!$restriction) {
            return $next($request);
        }

        $action = $requiredAction ?? $this->detectAction($request);

        if (!$restriction->canPerformAction($action)) {
            Log::channel('security')->warning('Access denied due to restrictions', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'action' => $action,
                'restriction_type' => $restriction->restriction_type,
                'access_level' => $restriction->access_level
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Действие ограничено',
                'error' => 'access_restricted',
                'details' => [
                    'reason' => $restriction->reason,
                    'access_level' => $restriction->access_level,
                    'expires_at' => $restriction->expires_at?->toIso8601String(),
                    'allowed_actions' => $restriction->allowed_actions,
                    'support_url' => url('/support'),
                ],
            ], 403);
        }

        return $next($request);
    }

    private function detectAction(Request $request): string
    {
        $method = $request->method();
        $routeAction = $request->route()?->getActionMethod();

        if ($routeAction && isset($this->actionMap[$routeAction])) {
            return $this->actionMap[$routeAction];
        }

        $path = $request->path();
        
        if (str_contains($path, 'export')) {
            return 'bulk_export';
        }
        
        if (str_contains($path, 'payment')) {
            return 'request_payments';
        }

        if (str_contains($path, 'acts')) {
            return 'create_acts';
        }

        if (str_contains($path, 'documents') || str_contains($path, 'files')) {
            return 'upload_documents';
        }

        if (str_contains($path, 'works')) {
            return 'edit_works';
        }

        return match($method) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'edit',
            'DELETE' => 'delete',
            default => 'view',
        };
    }
}

