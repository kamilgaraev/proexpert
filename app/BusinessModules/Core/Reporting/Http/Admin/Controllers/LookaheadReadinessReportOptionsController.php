<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\LookaheadReadinessReportOptionsRequest;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\LookaheadReadinessCandidateContract;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Services\LookaheadReadinessOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class LookaheadReadinessReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private LookaheadReadinessOptionsService $options,
    ) {}

    public function __invoke(LookaheadReadinessReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, LookaheadReadinessCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options(
            $context,
            $context->scope,
            $request->asOf(),
            $request->horizonDays(),
        ));
    }
}
