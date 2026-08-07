<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ManagementPnlReportOptionsRequest;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\ManagementPnlCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ManagementPnl\Services\ManagementPnlOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ManagementPnlReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ManagementPnlOptionsService $options,
        private ManagementPnlCandidateContract $contract,
    ) {}

    public function __invoke(ManagementPnlReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization->createRun($request, ManagementPnlCandidateContract::CODE)['context'];
        $query = new ReportQuery(
            $this->contract->definition(),
            $context->scope,
            new ReportFilterSet($request->reportFilters()),
            [],
            $request->asOf(),
            'ru-RU',
        );

        return AdminResponse::success($this->options->options($context, $query));
    }
}
