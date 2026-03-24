<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class SiteRequestDashboardController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $service
    ) {
    }

    public function statistics(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');

            return AdminResponse::success($this->service->getStatistics($organizationId));
        } catch (\Throwable $e) {
            Log::error('[SiteRequestDashboardController.statistics] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('site_requests.statistics_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function overdue(Request $request): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $overdue = $this->service->getOverdueRequests($organizationId);

            return AdminResponse::success([
                'items' => SiteRequestResource::collection($overdue)->resolve(),
                'count' => $overdue->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[SiteRequestDashboardController.overdue] Unexpected error', [
                'message' => $e->getMessage(),
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
            ]);

            return AdminResponse::error(
                trans_message('site_requests.overdue_load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
