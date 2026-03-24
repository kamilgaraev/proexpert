<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Middleware;

use App\Http\Responses\AdminResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class CheckSystemAnalysisAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return AdminResponse::error(trans_message('ai_assistant.unauthorized'), 401);
        }

        if (!config('ai-assistant.system_analysis.enabled', true)) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.disabled'), 403);
        }

        if (!$this->isAdmin($user)) {
            return AdminResponse::error(trans_message('ai_assistant.system_analysis.access_denied'), 403);
        }

        return $next($request);
    }

    private function isAdmin($user): bool
    {
        return $user->hasRole('admin')
            || $user->hasRole('super_admin')
            || $user->hasRole('organization_admin')
            || $user->role === 'admin';
    }
}
