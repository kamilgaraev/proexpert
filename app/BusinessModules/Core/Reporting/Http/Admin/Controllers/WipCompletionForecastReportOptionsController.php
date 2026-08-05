<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\WipCompletionForecastReportOptionsRequest;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectFinance\WipCompletionForecastOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class WipCompletionForecastReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private WipCompletionForecastOptionsService $options,
    ) {}

    public function __invoke(WipCompletionForecastReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, WipCompletionForecastCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope));
    }
}
