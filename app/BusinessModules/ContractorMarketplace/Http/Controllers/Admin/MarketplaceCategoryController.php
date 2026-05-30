<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Admin;

use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceWorkCategoryService;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceWorkCategoryResource;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
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
            return AdminResponse::success(
                MarketplaceWorkCategoryResource::collection($this->categoryService->activeTree())
            );
        } catch (\Throwable $e) {
            Log::error('Failed to fetch marketplace categories', [
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('contractor_marketplace.categories_load_error'), 500);
        }
    }
}
