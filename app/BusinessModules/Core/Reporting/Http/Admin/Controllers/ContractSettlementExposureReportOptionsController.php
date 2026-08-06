<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ContractSettlementExposureReportOptionsRequest;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureCandidateContract;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementExposureOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ContractSettlementExposureReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ContractSettlementExposureOptionsService $options,
    ) {}

    public function __invoke(ContractSettlementExposureReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, ContractSettlementExposureCandidateContract::CODE)['context'];

        return AdminResponse::success($this->options->options($context->scope, $request->asOf()));
    }
}
