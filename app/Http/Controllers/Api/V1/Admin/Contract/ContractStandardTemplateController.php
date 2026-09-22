<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\InstallContractStandardTemplateRequest;
use App\Http\Requests\Api\V1\Admin\Contract\InstallContractSystemFieldRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractLibraryResource;
use App\Http\Resources\Api\V1\Admin\Contract\ResolvedContractTemplateResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractStandardTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractStandardTemplateController extends Controller
{
    public function systemFields(Request $request, ContractStandardTemplateService $service): JsonResponse
    {
        return AdminResponse::success($service->systemFields($request->user(), (int) $request->attributes->get('current_organization_id')));
    }

    public function installSystemField(InstallContractSystemFieldRequest $request, ContractStandardTemplateService $service): JsonResponse
    {
        $result = $service->installSystemField($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated('code'));

        return AdminResponse::success((new ContractLibraryResource($result))->resolve($request));
    }

    public function index(Request $request, ContractStandardTemplateService $service): JsonResponse
    {
        return AdminResponse::success($service->catalogue($request->user(), (int) $request->attributes->get('current_organization_id')));
    }

    public function install(InstallContractStandardTemplateRequest $request, ContractStandardTemplateService $service): JsonResponse
    {
        $result = $service->install($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated('code'));

        return AdminResponse::success((new ResolvedContractTemplateResource($result))->resolve($request));
    }
}
