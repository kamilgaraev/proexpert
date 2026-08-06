<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\AcceptedProductionReportOptionsRequest;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Services\CompletedWork\Reporting\AcceptedProduction\AcceptedProductionCandidateContract;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Options\AcceptedProductionOptionsService;
use Illuminate\Http\JsonResponse;

final readonly class AcceptedProductionReportOptionsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private AcceptedProductionOptionsService $options,
    ) {}

    public function __invoke(AcceptedProductionReportOptionsRequest $request): JsonResponse
    {
        $context = $this->authorization
            ->createRun($request, AcceptedProductionCandidateContract::CODE)['context'];
        $project = $request->attributes->get('project');

        return AdminResponse::success($this->options->options(
            $context->scope,
            $project instanceof Project ? (int) $project->getKey() : 0,
            $request->asOf(),
            $request->periodFrom(),
            $request->periodTo(),
        ));
    }
}
