<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\PrepareContractRevisionArchiveRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractRevisionLegalArchiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractRevisionArchiveController extends Controller
{
    public function show(Request $request, int $contract, int $revision, ContractRevisionLegalArchiveService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision));
    }

    public function store(PrepareContractRevisionArchiveRequest $request, int $contract, int $revision, ContractRevisionLegalArchiveService $service): JsonResponse
    {
        return AdminResponse::success($service->prepare($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision, $request->validated('content_hash')));
    }
}
