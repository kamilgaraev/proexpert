<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\PlanFactDrillDownRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\PlanFactReportRequest;
use App\BusinessModules\Features\Budgeting\Services\PlanFactReportService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use function trans_message;

final class PlanFactReportController extends BudgetingAdminController
{
    public function __construct(
        private readonly PlanFactReportService $service,
    ) {
    }

    public function index(PlanFactReportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->report($this->inputWithOrganization($request)),
                trans_message('budgeting.plan_fact.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.plan_fact.load_error');
        }
    }

    public function drillDown(PlanFactDrillDownRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->drillDown($this->inputWithOrganization($request)),
                trans_message('budgeting.plan_fact.drill_down_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.plan_fact.drill_down_load_error');
        }
    }

    private function inputWithOrganization(Request $request): array
    {
        $validated = $request instanceof PlanFactReportRequest ? $request->validated() : $request->all();
        $currentOrganizationId = $request->attributes->get('current_organization_id')
            ?? $validated['current_organization_id']
            ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $validated['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new DomainException(trans_message('budgeting.plan_fact.errors.organization_mismatch'));
        }

        $validated['organization_id'] = (int) $currentOrganizationId;

        return $validated;
    }

    private function safeUnexpectedError(Throwable $exception, Request $request, string $messageKey): JsonResponse
    {
        Log::error('budgeting.plan_fact.api_failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'path' => $request->path(),
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message($messageKey), 500);
    }
}
