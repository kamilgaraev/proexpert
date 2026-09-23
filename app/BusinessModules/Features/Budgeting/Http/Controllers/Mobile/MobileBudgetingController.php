<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers\Mobile;

use App\BusinessModules\Features\Budgeting\Http\Requests\MobileBudgetingExecutionCardsRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\MobileBudgetingSummaryRequest;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileBudgetingService;
use Illuminate\Http\JsonResponse;

final class MobileBudgetingController
{
    public function __construct(private readonly MobileBudgetingService $service) {}

    public function summary(MobileBudgetingSummaryRequest $request, int $project): JsonResponse
    {
        return MobileResponse::success($this->service->summary(
            $request->user(),
            (int) $request->attributes->get('current_organization_id'),
            $project,
        ));
    }

    public function executionCards(MobileBudgetingExecutionCardsRequest $request, int $project): JsonResponse
    {
        $page = $this->service->executionCards(
            $request->user(),
            (int) $request->attributes->get('current_organization_id'),
            $project,
            (int) $request->validated('page', 1),
            (int) $request->validated('per_page', 20),
        );

        return MobileResponse::paginated($page->items(), [
            'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $page->lastPage(),
        ]);
    }
}
