<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ExportContractRevisionRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractBuilderExportService;
use Illuminate\Http\JsonResponse;

final class ContractBuilderExportController extends Controller
{
    public function store(ExportContractRevisionRequest $request, int $contract, int $revision, ContractBuilderExportService $service): JsonResponse
    {
        return AdminResponse::success($service->export($request->user(), (int) $request->attributes->get('current_organization_id'),
            $contract, $revision, $request->validated('format')));
    }
}
