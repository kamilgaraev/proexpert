<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\MultiOrganization\Reporting\HoldingPerformanceCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingPerformanceOptionsService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\HoldingPerformanceReportOptionsRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class HoldingPerformanceReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private HoldingPerformanceOptionsService $options,
    ) {}

    public function __invoke(HoldingPerformanceReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, HoldingPerformanceCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options(
            $context->scope,
            $request->asOf(),
            $request->periodFrom(),
            $request->periodTo(),
        ));
    }
}
