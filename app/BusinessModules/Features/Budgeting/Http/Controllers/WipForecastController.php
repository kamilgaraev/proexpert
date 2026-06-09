<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetingFormRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastAdjustmentRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastDrillDownRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastReportRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastVersionListRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastVersionRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastVersionUpdateRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\WipForecastWorkflowRequest;
use App\BusinessModules\Features\Budgeting\Services\WipForecastReportService;
use App\BusinessModules\Features\Budgeting\Services\WipForecastVersionService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;
use function trans_message;

final class WipForecastController extends BudgetingAdminController
{
    public function __construct(
        private readonly WipForecastReportService $reportService,
        private readonly WipForecastVersionService $versionService,
    ) {
    }

    public function index(WipForecastReportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->reportService->report($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.wip_forecast.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.load_error');
        }
    }

    public function drillDown(WipForecastDrillDownRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->reportService->drillDown($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.wip_forecast.drill_down_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.drill_down_load_error');
        }
    }

    public function versions(WipForecastVersionListRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->index($this->inputWithOrganization($request)),
                trans_message('budgeting.wip_forecast.versions_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.versions_load_error');
        }
    }

    public function storeVersion(WipForecastVersionRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->create($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.wip_forecast.version_created'),
                201,
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.version_create_error');
        }
    }

    public function showVersion(Request $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->show($versionUuid, $this->inputWithOrganization($request)),
                trans_message('budgeting.wip_forecast.version_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.version_load_error');
        }
    }

    public function updateVersion(WipForecastVersionUpdateRequest $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->update($versionUuid, $this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.wip_forecast.version_updated'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.version_update_error');
        }
    }

    public function submit(WipForecastWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'submit', 'budgeting.wip_forecast.version_submitted');
    }

    public function approve(WipForecastWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'approve', 'budgeting.wip_forecast.version_approved');
    }

    public function activate(WipForecastWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'activate', 'budgeting.wip_forecast.version_activated');
    }

    public function storeAdjustment(WipForecastAdjustmentRequest $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->addAdjustment($versionUuid, $this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.wip_forecast.adjustment_created'),
                201,
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.adjustment_create_error');
        }
    }

    public function audit(WipForecastWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->versionService->auditEvents($versionUuid, $this->inputWithOrganization($request)),
                trans_message('budgeting.wip_forecast.audit_loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.audit_load_error');
        }
    }

    private function workflow(WipForecastWorkflowRequest $request, string $versionUuid, string $action, string $messageKey): JsonResponse
    {
        try {
            $input = $this->inputWithOrganization($request);
            $user = $this->user($request);
            $payload = match ($action) {
                'submit' => $this->versionService->submit($versionUuid, $input, $user),
                'approve' => $this->versionService->approve($versionUuid, $input, $user),
                'activate' => $this->versionService->activate($versionUuid, $input, $user),
                default => throw new DomainException(trans_message('budgeting.wip_forecast.errors.workflow_action_unknown')),
            };

            return AdminResponse::success($payload, trans_message($messageKey));
        } catch (DomainException|InvalidArgumentException $exception) {
            return $this->domainError(new DomainException($exception->getMessage()));
        } catch (Throwable $exception) {
            return $this->safeUnexpectedError($exception, $request, 'budgeting.wip_forecast.workflow_error');
        }
    }

    private function inputWithOrganization(Request $request): array
    {
        $validated = $request instanceof BudgetingFormRequest ? $request->validated() : $request->all();
        $currentOrganizationId = $request->attributes->get('current_organization_id')
            ?? $validated['current_organization_id']
            ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $validated['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new DomainException(trans_message('budgeting.wip_forecast.errors.organization_mismatch'));
        }

        $validated['organization_id'] = (int) $currentOrganizationId;

        return $validated;
    }

    private function safeUnexpectedError(Throwable $exception, Request $request, string $messageKey): JsonResponse
    {
        Log::error('budgeting.wip_forecast.api_failed', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'path' => $request->path(),
            'exception_class' => $exception::class,
        ]);

        return AdminResponse::error(trans_message($messageKey), 500);
    }
}
