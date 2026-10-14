<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceSummaryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceSummaryController extends Controller
{
    public function __invoke(Request $request, WorkforceSummaryService $service): JsonResponse
    {
        return AdminResponse::success($service->summary((int) $request->attributes->get('current_organization_id'), $request->user()));
    }
}
