<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ConfirmContractRevisionRequest;
use App\Http\Requests\Api\V1\Admin\Contract\RecordExternalContractConfirmationRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractRevisionConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractRevisionConfirmationController extends Controller
{
    public function external(RecordExternalContractConfirmationRequest $request, int $contract, int $revision, ContractRevisionConfirmationService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->confirm($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision,
            $data['content_hash'], $data['request_key'], ['basis' => $data['basis'], 'asset_id' => (int) $data['asset_id'], 'side' => $data['side']]));
    }

    public function show(Request $request, int $contract, int $revision, ContractRevisionConfirmationService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision));
    }

    public function store(ConfirmContractRevisionRequest $request, int $contract, int $revision, ContractRevisionConfirmationService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->confirm($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision,
            $data['content_hash'], $data['request_key']));
    }
}
