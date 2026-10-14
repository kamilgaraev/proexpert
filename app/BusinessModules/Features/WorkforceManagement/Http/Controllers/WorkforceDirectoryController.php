<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Services\WorkforceDirectoryService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceDirectoryController extends Controller
{
    public function owners(Request $request, WorkforceDirectoryService $service): JsonResponse
    {
        return AdminResponse::success($service->owners((int) $request->attributes->get('current_organization_id'), $request->user()));
    }

    public function safetyWorkTypes(Request $request, WorkforceDirectoryService $service): JsonResponse
    {
        return AdminResponse::success($service->safetyWorkTypes((int) $request->attributes->get('current_organization_id'), $request->user()));
    }
}
