<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Http\Controllers;

use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestDecisionForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Http\RequestListForm;
use App\BusinessModules\Features\WorkforceManagement\Domain\SelfService\Services\TeamQueueService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WorkforceTeamQueueController extends Controller
{
    public function __construct(private readonly TeamQueueService $service) {}

    public function index(RequestListForm $request): JsonResponse
    {
        $result = $this->service->index((int) $request->attributes->get('current_organization_id'), $request->user(), $request->validated());

        return AdminResponse::paginated($result['data'], $result['meta'], summary: $result['summary']);
    }

    public function decision(RequestDecisionForm $request, int $requestId): JsonResponse
    {
        return $this->response(fn () => $this->service->decide((int) $request->attributes->get('current_organization_id'), $request->user(), $requestId, $request->validated()));
    }

    public function scopes(Request $request): JsonResponse
    {
        return $this->response(fn () => $this->service->scopes((int) $request->attributes->get('current_organization_id'), $request->user()));
    }

    public function storeScope(Request $request): JsonResponse
    {
        return $this->response(fn () => $this->service->storeScope((int) $request->attributes->get('current_organization_id'), $request->user(), $request->all()), 201);
    }

    private function response(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return AdminResponse::success($operation(), null, $status);
        } catch (\DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }
}
