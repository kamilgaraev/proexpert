<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Landing\SearchMarketplaceContractorsRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorListItemResource;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorProfileResource;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketplaceSearchController extends Controller
{
    public function __construct(
        private readonly MarketplaceSearchService $searchService
    ) {
    }

    public function index(SearchMarketplaceContractorsRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);
        $validated = $request->validated();
        $perPage = (int) ($validated['per_page'] ?? 20);
        unset($validated['per_page']);

        try {
            $profiles = $this->searchService->search($organizationId, $validated, $perPage);

            return LandingResponse::paginated(
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
        } catch (\Throwable $exception) {
            Log::error('Failed to search contractor marketplace from landing cabinet', [
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
                'filters' => $validated,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.search_error'), 500);
        }
    }

    public function show(Request $request, MarketplaceContractorProfile $profile): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        try {
            return LandingResponse::success(new MarketplaceContractorProfileResource(
                $this->searchService->showVisibleProfile($organizationId, $profile)
            ));
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to show marketplace contractor profile from landing cabinet', [
                'organization_id' => $organizationId,
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.profile_load_error'), 500);
        }
    }

    private function resolveOrganizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id);
    }
}
