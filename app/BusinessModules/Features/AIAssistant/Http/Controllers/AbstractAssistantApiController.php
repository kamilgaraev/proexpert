<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

abstract class AbstractAssistantApiController
{
    protected function actor(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    protected function organizationId(Request $request): int
    {
        $actor = $this->actor($request);
        $context = $request->attributes->get('current_organization_id') ?? $request->attributes->get('organization_id');
        $organizationId = $context ?? $actor->current_organization_id;
        if (! $actor->is_active || ! is_numeric($organizationId) || (int) $organizationId < 1 || (int) $actor->current_organization_id !== (int) $organizationId || ! $actor->belongsToOrganization((int) $organizationId)) {
            throw new AccessDeniedHttpException;
        }
        if (! app(AIPermissionChecker::class)->canUseAssistant($actor, (int) $organizationId)) {
            throw new AccessDeniedHttpException;
        }

        return (int) $organizationId;
    }

    protected function success(Request $request, mixed $data = null, int $status = 200, ?array $meta = null): JsonResponse
    {
        return $this->responseClass($request)::success($data, null, $status, $meta);
    }

    protected function error(Request $request, int $status, ?string $messageKey = null): JsonResponse
    {
        return $this->responseClass($request)::error(trans_message($messageKey ?? ($status === 403 ? 'errors.unauthorized' : 'errors.not_found')), $status);
    }

    private function responseClass(Request $request): string
    {
        $path = trim($request->path(), '/');

        return str_contains($path, 'admin/ai-assistant') ? AdminResponse::class : (str_contains($path, 'mobile/ai-assistant') ? MobileResponse::class : LandingResponse::class);
    }
}
