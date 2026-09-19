<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\SaveContractBuilderDraftRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractBuilderDraftResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractBuilderDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractBuilderDraftController extends Controller
{
    public function show(Request $request, int $contract, ContractBuilderDraftService $service): JsonResponse
    {
        $draft = $service->read($request->user(), (int) $request->attributes->get('current_organization_id'), $contract);

        return AdminResponse::success($draft === null ? null : (new ContractBuilderDraftResource($draft))->resolve($request));
    }

    public function store(SaveContractBuilderDraftRequest $request, int $contract, ContractBuilderDraftService $service): JsonResponse
    {
        $data = $request->validated();
        $draft = $service->save($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            (int) $data['base_revision'], (int) $data['expected_version'], $data['document'], $data['values'], $data['request_key'], $data['source_refresh_hash'] ?? null,
            isset($data['attachment_ids']) ? array_map('intval', $data['attachment_ids']) : null);

        return AdminResponse::success((new ContractBuilderDraftResource($draft))->resolve($request));
    }

    public function sourceChanges(Request $request, int $contract, ContractBuilderDraftService $service): JsonResponse
    {
        return AdminResponse::success($service->sourceChanges($request->user(), (int) $request->attributes->get('current_organization_id'), $contract));
    }

    public function preview(Request $request, int $contract, ContractBuilderDraftService $service): JsonResponse
    {
        $result = $service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract);

        return AdminResponse::success([
            'draft' => (new ContractBuilderDraftResource($result['draft']))->resolve($request), 'html' => $result['html'],
        ]);
    }
}
