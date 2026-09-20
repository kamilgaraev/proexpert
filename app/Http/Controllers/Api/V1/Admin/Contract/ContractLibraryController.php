<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\ChangeContractLibraryStateRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ListContractLibraryRequest;
use App\Http\Requests\Api\V1\Admin\Contract\ReadContractDefinitionsRequest;
use App\Http\Requests\Api\V1\Admin\Contract\SaveContractLibraryItemRequest;
use App\Http\Resources\Api\V1\Admin\Contract\ContractLibraryResource;
use App\Http\Resources\Api\V1\Admin\Contract\ResolvedContractTemplateResource;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractLibraryController extends Controller
{
    public function calculate(\App\Http\Requests\Api\V1\Admin\Contract\CalculateContractTemplateRequest $request, string $libraryItem, int $libraryVersion, ContractLibraryService $service): JsonResponse
    {
        $result = $service->calculateTemplate($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, $libraryVersion, $request->validated('values'));

        return AdminResponse::success((new \App\Http\Resources\Api\V1\Admin\Contract\CalculatedContractTemplateResource($result))->resolve($request));
    }

    public function resolved(Request $request, string $libraryItem, int $libraryVersion, ContractLibraryService $service): JsonResponse
    {
        $result = $service->resolveTemplate($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, $libraryVersion);

        return AdminResponse::success((new ResolvedContractTemplateResource([
            'template_id' => $libraryItem, 'template_version' => $libraryVersion, ...$result,
        ]))->resolve($request));
    }

    public function definitions(ReadContractDefinitionsRequest $request, ContractLibraryService $service): JsonResponse
    {
        $definitions = $service->definitions($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated('references'));

        return AdminResponse::success((object) $definitions);
    }

    public function index(ListContractLibraryRequest $request, ContractLibraryService $service): JsonResponse
    {
        $page = $service->list($request->user(), (int) $request->attributes->get('current_organization_id'), $request->validated());

        return AdminResponse::paginated($page->items(), [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]);
    }

    public function store(SaveContractLibraryItemRequest $request, ContractLibraryService $service): JsonResponse
    {
        $data = $request->validated();
        $result = $service->create($request->user(), (int) $request->attributes->get('current_organization_id'), $data['kind'], $data['title'], $data['content'], $data['request_key']);

        return AdminResponse::success((new ContractLibraryResource($result))->resolve($request));
    }

    public function show(Request $request, string $libraryItem, int $libraryVersion, ContractLibraryService $service): JsonResponse
    {
        $result = $service->read($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, $libraryVersion);

        return AdminResponse::success((new ContractLibraryResource($result))->resolve($request));
    }

    public function revise(SaveContractLibraryItemRequest $request, string $libraryItem, ContractLibraryService $service): JsonResponse
    {
        $data = $request->validated();
        $result = $service->revise($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, (int) $data['expected_version'], $data['title'], $data['content'], $data['request_key']);

        return AdminResponse::success((new ContractLibraryResource($result))->resolve($request));
    }

    public function publish(ChangeContractLibraryStateRequest $request, string $libraryItem, int $libraryVersion, ContractLibraryService $service): JsonResponse
    {
        $result = $service->publish($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, $libraryVersion, (int) $request->validated('expected_version'));

        return AdminResponse::success((new ContractLibraryResource($result))->resolve($request));
    }

    public function archive(ChangeContractLibraryStateRequest $request, string $libraryItem, ContractLibraryService $service): JsonResponse
    {
        $result = $service->archive($request->user(), (int) $request->attributes->get('current_organization_id'), $libraryItem, (int) $request->validated('expected_version'), (bool) $request->validated('archived'));

        return AdminResponse::success((new ContractLibraryResource(['item' => $result]))->resolve($request));
    }
}
