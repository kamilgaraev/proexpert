<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceSearchService;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorProfileResource;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketplaceProfileController extends Controller
{
    public function __construct(
        private readonly MarketplaceSearchService $searchService
    ) {
    }

    public function show(Request $request, MarketplaceContractorProfile $profile): JsonResponse
    {
        $organizationId = (int) (
            $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id
        );

        try {
            return AdminResponse::success(new MarketplaceContractorProfileResource(
                $this->searchService->showVisibleProfile($organizationId, $profile)
            ));
        } catch (BusinessLogicException $exception) {
            return AdminResponse::error($exception->getMessage(), (int) $exception->getCode() ?: 400);
        } catch (\Throwable $exception) {
            Log::error('Failed to show marketplace contractor profile', [
                'organization_id' => $organizationId,
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.profile_load_error'), 500);
        }
    }
}
