<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Dashboard\DashboardExportProjectsRequest;
use App\Http\Requests\Api\V1\Admin\Dashboard\DashboardOptionalProjectRequest;
use App\Http\Requests\Api\V1\Admin\Dashboard\DashboardRequiredProjectRequest;
use App\Http\Responses\AdminResponse;
use App\Services\Admin\DashboardExportService;
use App\Services\Admin\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly DashboardExportService $exportService,
    ) {
    }

    public function index(DashboardRequiredProjectRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId();

            if ($organizationId === null) {
                return $this->organizationRequiredResponse();
            }

            $dashboard = $this->dashboardService->getFullDashboard(
                $organizationId,
                (int) $request->validated('project_id'),
            );

            return AdminResponse::success($dashboard);
        } catch (Throwable $exception) {
            return $this->handleFailure($exception, 'dashboard.index', $request->validated());
        }
    }

    public function summary(DashboardRequiredProjectRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId();

            if ($organizationId === null) {
                return $this->organizationRequiredResponse();
            }

            $summary = $this->dashboardService->getSummary(
                $organizationId,
                (int) $request->validated('project_id'),
            );

            return AdminResponse::success($summary);
        } catch (Throwable $exception) {
            return $this->handleFailure($exception, 'dashboard.summary', $request->validated());
        }
    }

    public function financialMetrics(DashboardOptionalProjectRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId();

            if ($organizationId === null) {
                return $this->organizationRequiredResponse();
            }

            $data = $this->dashboardService->getFinancialMetrics(
                $organizationId,
                $this->validatedNullableInt($request->validated('project_id')),
            );

            return AdminResponse::success($data);
        } catch (Throwable $exception) {
            return $this->handleFailure($exception, 'dashboard.financial_metrics', $request->validated());
        }
    }

    public function projectsAnalytics(DashboardOptionalProjectRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId();

            if ($organizationId === null) {
                return $this->organizationRequiredResponse();
            }

            $data = $this->dashboardService->getProjectsAnalytics(
                $organizationId,
                $request->safe()->only(['status', 'is_archived']),
            );

            return AdminResponse::success($data);
        } catch (Throwable $exception) {
            return $this->handleFailure($exception, 'dashboard.projects_analytics', $request->validated());
        }
    }

    public function exportProjects(DashboardExportProjectsRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->currentOrganizationId();

            if ($organizationId === null) {
                return $this->organizationRequiredResponse();
            }

            $export = $this->exportService->exportProjectsForMap(
                $organizationId,
                $request->validated(),
            );

            return AdminResponse::success($export);
        } catch (Throwable $exception) {
            return $this->handleFailure($exception, 'dashboard.export_projects', $request->validated());
        }
    }

    private function currentOrganizationId(): ?int
    {
        $organizationId = Auth::user()?->current_organization_id;

        return $organizationId === null ? null : (int) $organizationId;
    }

    private function validatedNullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function organizationRequiredResponse(): JsonResponse
    {
        return AdminResponse::error(
            trans_message('dashboard.organization_required'),
            Response::HTTP_BAD_REQUEST,
        );
    }

    private function handleFailure(Throwable $exception, string $operation, array $context = []): JsonResponse
    {
        Log::error('Admin dashboard request failed', [
            'operation' => $operation,
            'user_id' => Auth::id(),
            'organization_id' => Auth::user()?->current_organization_id,
            'context' => $context,
            'exception' => $exception,
        ]);

        return AdminResponse::error(
            trans_message('dashboard.request_failed'),
            Response::HTTP_INTERNAL_SERVER_ERROR,
        );
    }
}
