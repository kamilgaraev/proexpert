<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ProjectPortfolioHealthReportOptionsRequest;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\BudgetingPortfolioProjectionService;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\ProjectPortfolioHealthOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ProjectPortfolioHealthReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ProjectPortfolioHealthOptionsService $options,
    ) {}

    public function __invoke(ProjectPortfolioHealthReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, BudgetingPortfolioProjectionService::HEALTH_CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope));
    }
}
