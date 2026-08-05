<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\MultiOrganization\Reporting\IntercompanyContractFlowCandidateContract;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\IntercompanyContractFlowOptionsService;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\IntercompanyContractFlowReportOptionsRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class IntercompanyContractFlowReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private IntercompanyContractFlowOptionsService $options,
    ) {}

    public function __invoke(IntercompanyContractFlowReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, IntercompanyContractFlowCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope, $request->asOf()));
    }
}
