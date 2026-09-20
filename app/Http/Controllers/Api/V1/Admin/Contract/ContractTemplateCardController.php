<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\PrepareContractTemplateCardRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractTemplateCardService;
use Illuminate\Http\JsonResponse;

final class ContractTemplateCardController extends Controller
{
    public function show(\Illuminate\Http\Request $request, int $contract, ContractTemplateCardService $service): JsonResponse
    {
        return AdminResponse::success($service->read($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function prepare(PrepareContractTemplateCardRequest $request, ContractTemplateCardService $service): JsonResponse
    {
        $prepared = $service->prepare($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated());
        foreach (['definitions', 'blocks', 'values', 'entity_snapshots'] as $field) {
            $prepared[$field] = (object) $prepared[$field];
        }

        return AdminResponse::success($prepared);
    }
}
