<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectMarginDrillDownRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectMarginReportRequest;
use App\BusinessModules\Features\Budgeting\Services\ProjectMarginReportService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use function trans_message;

final class ProjectMarginReportController extends BudgetingAdminController
{
    public function __construct(
        private readonly ProjectMarginReportService $service,
    ) {
    }

    public function index(ProjectMarginReportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->report($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.project_margin.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.project_margin.load_error');
        }
    }

    public function drillDown(ProjectMarginDrillDownRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->drillDown($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.project_margin.drill_down_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.project_margin.drill_down_load_error');
        }
    }

    private function inputWithOrganization(Request $request): array
    {
        $validated = $request instanceof ProjectMarginReportRequest ? $request->validated() : $request->all();
        $currentOrganizationId = $request->attributes->get('current_organization_id')
            ?? $validated['current_organization_id']
            ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $validated['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new DomainException(trans_message('budgeting.project_margin.errors.organization_mismatch'));
        }

        $validated['organization_id'] = (int) $currentOrganizationId;

        return $validated;
    }

    private function safeUnexpectedError(Throwable $exception, Request $request, string $messageKey): JsonResponse
    {
        Log::error('budgeting.project_margin.api_failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'path' => $request->path(),
            'error' => $exception->getMessage(),
        ]);

        return AdminResponse::error(trans_message($messageKey), 500);
    }
}
