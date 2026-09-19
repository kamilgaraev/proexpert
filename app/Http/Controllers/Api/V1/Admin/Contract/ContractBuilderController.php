<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\CreateContractBuilderInstanceRequest;
use App\Http\Responses\AdminResponse;
use App\Http\Resources\Api\V1\Admin\Contract\ContractBuilderRevisionResource;
use App\Services\Contract\ContractBuilderInstanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractBuilderController extends Controller
{
    public function state(Request $request, int $contract, ContractBuilderInstanceService $service): JsonResponse
    {
        $state = $service->state($request->user(), (int) $request->attributes->get('current_organization_id'), $contract);

        return AdminResponse::success([
            'can_create' => $state['can_create'],
            'can_adopt' => $state['can_adopt'] ?? false,
            'can_edit_draft' => $state['can_edit_draft'],
            'revision' => $state['revision'] === null ? null : (new ContractBuilderRevisionResource($state['revision']))->resolve($request),
        ]);
    }

    public function store(CreateContractBuilderInstanceRequest $request, int $contract, ContractBuilderInstanceService $service): JsonResponse
    {
        $data = $request->validated();
        $revision = $service->create($request->user(), (int) $request->attributes->get('current_organization_id'), $contract,
            $data['template_id'], (int) $data['template_version'], $data['values'], $data['request_key']);

        return AdminResponse::success((new ContractBuilderRevisionResource($revision))->resolve($request));
    }

    public function show(Request $request, int $contract, int $revision, ContractBuilderInstanceService $service): JsonResponse
    {
        $data = $service->read($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision);

        return AdminResponse::success((new ContractBuilderRevisionResource($data))->resolve($request));
    }

    public function preview(Request $request, int $contract, int $revision, ContractBuilderInstanceService $service): JsonResponse
    {
        $data = $service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $revision);

        return AdminResponse::success([
            'revision' => (new ContractBuilderRevisionResource($data['revision']))->resolve($request), 'html' => $data['html'],
        ]);
    }
}
