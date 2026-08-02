<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Features\Budgeting\Services\BudgetPlanFactReportOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class BudgetPlanFactReportOptionsController
{
    public function __construct(private BudgetPlanFactReportOptionsService $options) {}

    public function __invoke(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');

        return AdminResponse::success([
            'closures' => $this->options->availableClosures((int) $organization->id),
        ]);
    }
}
