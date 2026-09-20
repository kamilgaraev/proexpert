<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractRevisionDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractRevisionDocumentController extends Controller
{
    public function show(Request $request, int $contract, int $revision, ContractRevisionDocumentService $service): JsonResponse
    {
        return AdminResponse::success($service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision));
    }

    public function retry(Request $request, int $contract, int $revision, ContractRevisionDocumentService $service): JsonResponse
    {
        return AdminResponse::success($service->retry($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision));
    }
}
