<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing;

use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceWorkCategoryService;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceWorkCategoryResource;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MarketplaceCategoryController extends Controller
{
    public function __construct(
        private readonly MarketplaceWorkCategoryService $categoryService
    ) {
    }

    public function index(): JsonResponse
    {
        try {
            return LandingResponse::success(
                MarketplaceWorkCategoryResource::collection($this->categoryService->activeTree())
            );
        } catch (\Throwable $exception) {
            Log::error('Failed to fetch landing marketplace categories', [
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.categories_load_error'), 500);
        }
    }
}
