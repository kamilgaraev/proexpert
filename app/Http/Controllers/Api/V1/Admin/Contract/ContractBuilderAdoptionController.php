<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\AdoptLegacyContractRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractBuilderRevisionResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractBuilderAdoptionService;
use App\Services\Contract\ContractBuilderInstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractBuilderAdoptionController extends Controller
{
    public function preview(Request $request, int $contract, ContractBuilderAdoptionService $service): JsonResponse
    {
        return AdminResponse::success($service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function store(AdoptLegacyContractRequest $request, int $contract, ContractBuilderInstanceService $service): JsonResponse
    {
        $data = $request->validated();
        $revision = $service->create($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            $data['template_id'], (int) $data['template_version'], $data['values'], $data['request_key'], ['fingerprint' => $data['fingerprint'], 'basis' => $data['basis']]);

        return AdminResponse::success((new ContractBuilderRevisionResource($revision))->resolve($request));
    }
}
