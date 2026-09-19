<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\PreviewContractPartiesRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractPartyPreviewService;
use Illuminate\Http\JsonResponse;

final class ContractPartyPreviewController extends Controller
{
    public function __invoke(PreviewContractPartiesRequest $request, ContractPartyPreviewService $service): JsonResponse
    {
        return AdminResponse::success($service->preview(
            $request->user(),
            (int) $request->attributes->get('current_organization_id'),
            $request->validated(),
        ));
    }
}
