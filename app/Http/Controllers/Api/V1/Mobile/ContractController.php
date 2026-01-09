<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Contract\ContractService;
use Illuminate\Support\Facades\Auth;
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Responses\MobileResponse;

class ContractController extends Controller
{
    public function __construct(protected ContractService $contractService) {}

    /**
     * Получить контракты конкретного проекта, доступные прорабу.
     */
    public function getProjectContracts(int $projectId)
    {
        $organizationId = Auth::user()->current_organization_id;
        // Только активные контракты по проекту
        $filters = ['project_id' => $projectId, 'status' => 'active'];
        $contractsPaginator = $this->contractService->getAllContracts($organizationId, 1000, $filters, 'date', 'desc');
        return MobileResponse::success(ContractMiniResource::collection(collect($contractsPaginator->items())));
    }
} 