<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\EnableContractOrganizationViewsRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractOrganizationViewResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractOrganizationEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractOrganizationEnrollmentController extends Controller
{
    public function preview(Request $request, int $contract, ContractOrganizationEnrollmentService $service): JsonResponse
    {
        return AdminResponse::success($service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function enable(EnableContractOrganizationViewsRequest $request, int $contract, ContractOrganizationEnrollmentService $service): JsonResponse
    {
        $data = $request->validated();
        $view = $service->enable($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $data['fingerprint'], $data['reason']);

        return AdminResponse::success((new ContractOrganizationViewResource($view))->resolve($request));
    }
}
