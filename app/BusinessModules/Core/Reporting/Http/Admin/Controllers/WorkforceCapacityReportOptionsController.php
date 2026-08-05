<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\WorkforceCapacityReportOptionsRequest;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityCandidateContract;
use App\BusinessModules\Features\WorkforceManagement\Reporting\WorkforceCapacityOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class WorkforceCapacityReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private WorkforceCapacityOptionsService $options,
    ) {}

    public function __invoke(WorkforceCapacityReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, WorkforceCapacityCandidateContract::CODE)['context'];
        $type = $request->type();
        $payload = $type === null
            ? $this->options->summary($context->scope)
            : $this->options->search($context->scope, $type, $request->search(), $request->page());

        return AdminResponse::success($payload);
    }
}
