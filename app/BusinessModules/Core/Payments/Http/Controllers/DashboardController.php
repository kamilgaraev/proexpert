<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Http\Requests\PaymentProjectScopeRequest;
use App\BusinessModules\Core\Payments\Services\PaymentDashboardQueryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

use function trans_message;

class DashboardController extends Controller
{
    public function __construct(private readonly PaymentDashboardQueryService $dashboard) {}

    public function index(PaymentProjectScopeRequest $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        try {
            $period = max((int) $request->input('period', 30), 1);

            return AdminResponse::success(
                $this->dashboard->getDashboard($request->user(), $organizationId, $period, $request->projectId()),
                trans_message('payments.dashboard.loaded')
            );
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('payments.dashboard.error', ['organization_id' => $organizationId, 'error' => $e->getMessage()]);

            return AdminResponse::error(trans_message('payments.dashboard.load_error'), 500);
        }
    }
}
