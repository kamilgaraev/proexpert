<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ProjectMarginReportOptionsController
{
    public function __construct(private BudgetingReportOptionsService $options) {}

    public function __invoke(Request $request): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');

        return AdminResponse::success([
            'closures' => $this->options->availableClosures(
                (int) $organization->id,
                ProjectMarginCandidateContract::CODE,
                ProjectMarginCandidateContract::FORMULA_VERSION,
            ),
        ]);
    }
}
