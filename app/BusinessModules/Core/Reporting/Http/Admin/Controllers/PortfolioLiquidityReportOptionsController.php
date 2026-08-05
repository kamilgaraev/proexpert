<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\PortfolioLiquidityReportOptionsRequest;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\Portfolio\PortfolioLiquidityOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class PortfolioLiquidityReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private PortfolioLiquidityOptionsService $options,
    ) {}

    public function __invoke(PortfolioLiquidityReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, PortfolioLiquidityCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope));
    }
}
