<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ApplyContractTemplateUpdateRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractBuilderDraftResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractTemplateUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractTemplateUpdateController extends Controller
{
    public function preview(Request $request, int $contract, ContractTemplateUpdateService $service): JsonResponse
    {
        $data = $service->preview($request->user(), (int) $request->attributes->get('current_organization_id'), $contract);
        if ($data['available']) {
            $data['local_document'] = $data['local']['document'];
            $data['local_definitions'] = (object) $data['local']['definitions'];
            $data['template_document'] = $data['next']['document'];
            $data['definitions'] = (object) $data['definitions'];
            $data['values'] = (object) $data['values'];
            unset($data['local'], $data['next'], $data['target_version_id']);
        }

        return AdminResponse::success($data);
    }

    public function apply(ApplyContractTemplateUpdateRequest $request, int $contract, ContractTemplateUpdateService $service): JsonResponse
    {
        $data = $request->validated();
        foreach (['base_revision', 'expected_version', 'target_version'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $draft = $service->apply($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $data);

        return AdminResponse::success((new ContractBuilderDraftResource($draft))->resolve($request));
    }
}
