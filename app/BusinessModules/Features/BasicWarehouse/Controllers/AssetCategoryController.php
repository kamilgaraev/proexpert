<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Controllers;

use App\BusinessModules\Features\BasicWarehouse\Http\Requests\SearchAssetCategoryRequest;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetCategoryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final class AssetCategoryController extends Controller
{
    public function index(SearchAssetCategoryRequest $request, AssetCategoryService $categories): JsonResponse
    {
        return AdminResponse::success($categories->search(
            (int) $request->user()->current_organization_id,
            (string) ($request->validated('search') ?? ''),
        ));
    }
}
