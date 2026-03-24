<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\BusinessModules\Features\Notifications\Services\AnalyticsService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use function trans_message;

class NotificationAnalyticsController extends Controller
{
    public function __construct(
        protected AnalyticsService $analyticsService
    ) {
    }

    public function getStats(Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['organization_id', 'channel', 'from_date', 'to_date']);

            return AdminResponse::success($this->analyticsService->getStats($filters));
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'getStats',
                $e,
                $request,
                trans_message('notifications.analytics_load_error')
            );
        }
    }

    public function getStatsByChannel(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->analyticsService->getStatsByChannel($request->query('organization_id'))
            );
        } catch (\Throwable $e) {
            return $this->handleUnexpectedError(
                'getStatsByChannel',
                $e,
                $request,
                trans_message('notifications.analytics_channel_load_error')
            );
        }
    }

    private function handleUnexpectedError(
        string $action,
        \Throwable $e,
        Request $request,
        string $message
    ): JsonResponse {
        Log::error("[NotificationAnalyticsController.{$action}] Unexpected error", [
            'message' => $e->getMessage(),
            'user_id' => $request->user()?->id,
            'organization_id' => $request->query('organization_id'),
        ]);

        return AdminResponse::error($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
