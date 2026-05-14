<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\MobileResponse;
use App\Modules\Core\AccessController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureQualityControlActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');

        if ($organizationId <= 0) {
            return $this->errorResponse(
                $request,
                trans_message('quality_control.errors.organization_missing'),
                403,
                null,
                ['error_code' => 'ORGANIZATION_NOT_DEFINED']
            );
        }

        $accessController = app(AccessController::class);

        foreach (['quality-control', 'project-management', 'contract-management', 'budget-estimates', 'file-management'] as $moduleSlug) {
            if (!$accessController->hasModuleAccess($organizationId, $moduleSlug)) {
                return $this->errorResponse(
                    $request,
                    trans_message('quality_control.errors.module_not_active'),
                    403,
                    null,
                    [
                        'module' => $moduleSlug,
                        'error_code' => 'MODULE_NOT_ACTIVE',
                    ]
                );
            }
        }

        return $next($request);
    }

    private function errorResponse(
        Request $request,
        string $message,
        int $code,
        mixed $errors = null,
        array $extra = []
    ): Response {
        $path = $request->path();

        if (str_starts_with($path, 'api/v1/mobile/')) {
            return MobileResponse::error($message, $code, $errors, $extra);
        }

        if (str_starts_with($path, 'api/v1/customer/')) {
            return CustomerResponse::error($message, $code, $errors, $extra);
        }

        return AdminResponse::error($message, $code, $errors, $extra);
    }
}
