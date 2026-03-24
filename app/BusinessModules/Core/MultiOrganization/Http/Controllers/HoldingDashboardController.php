<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Http\Controllers;

use App\BusinessModules\Core\MultiOrganization\Models\OrganizationMetrics;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class HoldingDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $orgId = (int) $request->attributes->get('current_organization_id');
            $org = Organization::findOrFail($orgId);

            if (!$org->is_holding) {
                return AdminResponse::error(trans_message('holding.access_denied'), Response::HTTP_FORBIDDEN);
            }

            $metrics = OrganizationMetrics::getHoldingMetrics($orgId);

            return AdminResponse::success([
                'holding_info' => [
                    'id' => $org->id,
                    'name' => $org->name,
                    'subdomain' => parse_url($request->url(), PHP_URL_HOST),
                ],
                'summary' => $metrics['total'],
                'organizations' => $metrics['by_organization'],
                'last_update' => $metrics['last_update'],
            ]);
        } catch (\Throwable $e) {
            Log::error('[HoldingDashboardController.index] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('holding.dashboard_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
