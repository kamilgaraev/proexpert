<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin;

use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Admin\SearchMarketplaceContractorsRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorListItemResource;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MarketplaceSearchController extends Controller
{
    public function __construct(
        private readonly MarketplaceSearchService $searchService
    ) {
    }

    public function index(SearchMarketplaceContractorsRequest $request): JsonResponse
    {
        $organizationId = (int) ($request->attributes->get('current_organization_id') ?? $request->user()->current_organization_id);
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 20);
        unset($validated['per_page']);

        try {
            $profiles = $this->searchService->search($organizationId, $validated, $perPage);

            return AdminResponse::paginated(
                MarketplaceContractorListItemResource::collection($profiles->getCollection()),
                [
                    'current_page' => $profiles->currentPage(),
                    'last_page' => $profiles->lastPage(),
                    'per_page' => $profiles->perPage(),
                    'total' => $profiles->total(),
                    'filters' => $validated,
                ],
                null,
                200,
                [
                    'network_size' => count($this->searchService->networkOrganizationIds($organizationId)),
                ],
                [
                    'first' => $profiles->url(1),
                    'last' => $profiles->url($profiles->lastPage()),
                    'prev' => $profiles->previousPageUrl(),
                    'next' => $profiles->nextPageUrl(),
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to search contractor marketplace', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'filters' => $validated,
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.search_error'), 500);
        }
    }
}
