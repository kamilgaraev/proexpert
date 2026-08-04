<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ProjectLaborCostReportOptionsRequest;
use App\BusinessModules\Features\TimeTracking\Reporting\ProjectLaborCostOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ProjectLaborCostReportOptionsController
{
    public function __construct(private ProjectLaborCostOptionsService $options) {}

    public function __invoke(ProjectLaborCostReportOptionsRequest $request): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');
        $project = $request->attributes->get('project');

        $type = $request->type();
        $payload = $type === null
            ? $this->options->summary((int) $organization->id, (int) $project->id)
            : $this->options->search(
                (int) $organization->id,
                (int) $project->id,
                $type,
                $request->search(),
                $request->page(),
            );

        return AdminResponse::success($payload);
    }
}
