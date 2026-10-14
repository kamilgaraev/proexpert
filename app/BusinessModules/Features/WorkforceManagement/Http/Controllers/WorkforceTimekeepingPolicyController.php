<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services\TimekeepingPolicyService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceTimekeepingPolicyController extends Controller
{
    public function __construct(private readonly TimekeepingPolicyService $service) {}

    public function show(Request $request): JsonResponse
    {
        return AdminResponse::success($this->service->show((int) $request->attributes->get('current_organization_id'), $request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        try {
            return AdminResponse::success($this->service->update((int) $request->attributes->get('current_organization_id'), $request->user(), $request->all()));
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
