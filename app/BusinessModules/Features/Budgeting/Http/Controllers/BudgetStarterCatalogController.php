<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetStarterCatalogRequest;
use App\BusinessModules\Features\Budgeting\Services\BudgetStarterCatalogService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final class BudgetStarterCatalogController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetStarterCatalogService $service) {}

    public function store(BudgetStarterCatalogRequest $request): JsonResponse
    {
        return AdminResponse::success(
            ['created' => $this->service->apply($this->user($request), $request->validated('groups'))],
            trans_message('budgeting.catalogs.starter_added')
        );
    }
}
