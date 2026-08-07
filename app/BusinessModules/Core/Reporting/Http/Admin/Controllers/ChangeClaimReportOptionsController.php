<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ChangeClaimReportOptionsRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\ChangeClaimCandidateContract;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services\ChangeClaimOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ChangeClaimReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ChangeClaimOptionsService $options,
        private ChangeClaimCandidateContract $contract,
    ) {}

    public function __invoke(ChangeClaimReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization->createRun($request, ChangeClaimCandidateContract::CODE)['context'];
        $query = new ReportQuery($this->contract->definition(), $context->scope, new ReportFilterSet($request->reportFilters()), [], $request->asOf(), 'ru-RU');

        return AdminResponse::success($this->options->options($context, $query));
    }
}
