<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ActivateContractRevisionRequest;
use App\Http\Requests\Api\V1\Admin\Contract\CancelContractActivationRequest;
use App\Http\Responses\AdminResponse;
use App\Http\Resources\Api\V1\Admin\Contract\ContractActivationResource;
use App\Services\Contract\ContractRevisionActivationService;
use App\Services\Contract\ContractRevisionApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractRevisionActivationController extends Controller
{
    public function index(Request $request, int $contract, ContractRevisionActivationService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function preview(Request $request, int $contract, int $revision, ContractRevisionActivationService $service): JsonResponse
    {
        return AdminResponse::success($service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision));
    }

    public function show(Request $request, int $contract, int $activation, ContractRevisionActivationService $service): JsonResponse
    {
        return AdminResponse::success(new ContractActivationResource($service->show($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $activation)));
    }

    public function store(ActivateContractRevisionRequest $request, int $contract, int $revision, ContractRevisionActivationService $service, ContractRevisionApplicationService $applications): JsonResponse
    {
        $data = $request->validated();
        $activation = $service->schedule($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision,
            $data['content_hash'], $data['previous_revision_id'] === null ? null : (int) $data['previous_revision_id'], $data['effective_date'], $data['basis'], $data['request_key']);

        return AdminResponse::success(new ContractActivationResource($applications->applyDue($activation['id'])));
    }

    public function retry(Request $request, int $contract, int $activation, ContractRevisionApplicationService $service): JsonResponse
    {
        return AdminResponse::success(new ContractActivationResource($service->retry($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $activation)));
    }

    public function cancel(CancelContractActivationRequest $request, int $contract, int $activation, ContractRevisionActivationService $service): JsonResponse
    {
        return AdminResponse::success(new ContractActivationResource($service->cancel($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $activation,
            $request->validated('basis'), $request->validated('request_key'))));
    }
}
