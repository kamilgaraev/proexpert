<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\UploadContractBuilderAssetRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractBuilderAssetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractBuilderAssetController extends Controller
{
    public function store(UploadContractBuilderAssetRequest $request, int $contract, ContractBuilderAssetService $service): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($service->upload($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            $request->file('file'), $data['kind'], $data['request_key']));
    }

    public function download(Request $request, int $contract, int $asset, ContractBuilderAssetService $service): JsonResponse
    {
        return AdminResponse::success($service->download($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $asset));
    }
}
