<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ProjectEvmControlReportOptionsRequest;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\ProjectEvmControlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Services\ProjectEvmControlOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ProjectEvmControlReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ProjectEvmControlOptionsService $options,
    ) {}

    public function __invoke(ProjectEvmControlReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, ProjectEvmControlCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope, $request->asOf()));
    }
}
