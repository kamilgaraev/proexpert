<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Http\Controllers;

use App\BusinessModules\Core\Payments\Services\ContractRevisionPaymentPlanService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractRevisionPaymentPlanController extends Controller
{
    public function index(Request $request, int $contract, ContractRevisionPaymentPlanService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function store(Request $request, int $contract, int $activation, ContractRevisionPaymentPlanService $service): JsonResponse
    {
        return AdminResponse::success($service->apply($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $activation));
    }
}
