<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\UpdateContractOrganizationViewRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractOrganizationViewResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractOrganizationViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractOrganizationViewController extends Controller
{
    public function index(\App\Http\Requests\Api\V1\Admin\Contract\ListContractOrganizationViewsRequest $request, ContractOrganizationViewService $service): JsonResponse
    {
        $page = $service->list($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated());

        return AdminResponse::paginated(ContractOrganizationViewResource::collection($page->items()), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function history(\App\Http\Requests\Api\V1\Admin\Contract\ListContractOrganizationHistoryRequest $request, int $contract, ContractOrganizationViewService $service): JsonResponse
    {
        $page = $service->history($request->user(), (int) $request->attributes->get('current_organization_id'), $contract, $request->validated());

        return AdminResponse::paginated($page->items(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function show(Request $request, int $contract, ContractOrganizationViewService $service): JsonResponse
    {
        $view = $service->find($request->user(), (int) $request->attributes->get('current_organization_id'), $contract);

        return AdminResponse::success((new ContractOrganizationViewResource($view))->resolve($request));
    }

    public function transition(\App\Http\Requests\Api\V1\Admin\Contract\TransitionContractOrganizationViewRequest $request, int $contract, ContractOrganizationViewService $service): JsonResponse
    {
        $data = $request->validated();
        $view = $service->transition(
            $request->user(), (int) $request->attributes->get('current_organization_id'),
            $contract, $data['action'], (int) $data['version'],
        );

        return AdminResponse::success((new ContractOrganizationViewResource($view))->resolve($request));
    }

    public function update(UpdateContractOrganizationViewRequest $request, int $contract, ContractOrganizationViewService $service): JsonResponse
    {
        $data = $request->validated();
        $view = $service->updateNotes(
            $request->user(), (int) $request->attributes->get('current_organization_id'),
            $contract, $data['private_notes'], (int) $data['version'],
        );

        return AdminResponse::success((new ContractOrganizationViewResource($view))->resolve($request));
    }
}
