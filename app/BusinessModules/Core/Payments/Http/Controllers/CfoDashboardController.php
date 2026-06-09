<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Http\Requests\CfoDashboardRequest;
use App\BusinessModules\Core\Payments\Services\CfoDashboardService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

final class CfoDashboardController extends Controller
{
    public function __construct(
        private readonly CfoDashboardService $dashboardService,
    ) {
    }

    public function index(CfoDashboardRequest $request): JsonResponse
    {
        try {
            $result = $this->dashboardService->build($request->filters());

            return AdminResponse::success(
                $result['data'],
                trans_message('payments.cfo_dashboard.loaded'),
                200,
                $result['meta'],
            );
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('payments.validation_error'), 422, $e->errors());
        } catch (\Throwable $e) {
            Log::error('payments.cfo_dashboard.load_failed', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'filters' => $request->only([
                    'company_id',
                    'project_id',
                    'responsibility_center_id',
                    'period_start',
                    'period_end',
                    'period_days',
                    'forecast_days',
                    'currency',
                ]),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('payments.cfo_dashboard.load_error'), 500);
        }
    }
}
