<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\MobileResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class EnsureProcurementActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->attributes->get('current_organization_id');
        $responseClass = $request->is('api/v1/mobile/*') ? MobileResponse::class : AdminResponse::class;

        if (!$organizationId) {
            return $responseClass::error(
                trans_message('procurement.organization_missing'),
                403,
                null,
                ['error_code' => 'ORGANIZATION_NOT_DEFINED']
            );
        }

        $accessController = app(\App\Modules\Core\AccessController::class);

        if (!$accessController->hasModuleAccess($organizationId, 'procurement')) {
            return $responseClass::error(
                trans_message('procurement.module_not_active'),
                403,
                null,
                [
                    'module' => 'procurement',
                    'error_code' => 'MODULE_NOT_ACTIVE',
                ]
            );
        }

        if (!$accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            return $responseClass::error(
                trans_message('procurement.dependency_not_active'),
                403,
                null,
                [
                    'module' => 'basic-warehouse',
                    'error_code' => 'DEPENDENCY_NOT_ACTIVE',
                ]
            );
        }

        return $next($request);
    }
}
