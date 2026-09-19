<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Contract;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Contract\SearchContractEntitiesRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Contract\ContractEntityCatalog;
use Illuminate\Http\JsonResponse;

final class ContractEntityController extends Controller
{
    public function index(SearchContractEntitiesRequest $request, ContractEntityCatalog $catalog): JsonResponse
    {
        $data = $request->validated();

        return AdminResponse::success($catalog->search($request->user(),
            (int) $request->attributes->get('current_organization_id'), $data['type'], $data['search'] ?? ''));
    }
}
