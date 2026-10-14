<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\EmployeeOverviewForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\EmployeeRequestForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestListForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\EmployeeWorkspaceService;
use App\Http\Controllers\Controller;
use App\Http\Responses\MobileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceSelfServiceController extends Controller
{
    public function __construct(private readonly EmployeeWorkspaceService $service) {}

    public function index(EmployeeOverviewForm $request): JsonResponse
    {
        $data = $request->validated();

        return $this->response(fn () => $this->service->overview((int) $request->attributes->get('current_organization_id'), $request->user(), $data['month'] ?? now()->format('Y-m'), $data['work_date'] ?? null));
    }

    public function requests(RequestListForm $request): JsonResponse
    {
        $result = $this->service->requests((int) $request->attributes->get('current_organization_id'), $request->user(), $request->validated());

        return MobileResponse::paginated($result['data'], $result['meta']);
    }

    public function store(EmployeeRequestForm $request): JsonResponse
    {
        return $this->response(fn () => $this->service->submit((int) $request->attributes->get('current_organization_id'), $request->user(), $request->validated()), 201);
    }

    public function download(Request $request, int $documentId): JsonResponse
    {
        return $this->response(fn () => $this->service->download((int) $request->attributes->get('current_organization_id'), $request->user(), $documentId));
    }

    private function response(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return MobileResponse::success($operation(), null, $status);
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), 422);
        }
    }
}
