<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Http\Admin\Requests\PayrollReadinessReportOptionsRequest;
use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadinessOptionsService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class PayrollReadinessReportOptionsController
{
    public function __construct(private PayrollReadinessOptionsService $options) {}

    public function __invoke(PayrollReadinessReportOptionsRequest $request): JsonResponse
    {
        $organization = $request->attributes->get('current_organization');
        $project = $request->attributes->get('project');
        $type = $request->type();
        $payload = $type === null
            ? $this->options->summary((int) $organization->id, (int) $project->id)
            : $this->options->search((int) $organization->id, (int) $project->id, $type, $request->search(), $request->page());

        return AdminResponse::success($payload);
    }
}
