<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\ProjectPortfolioDashboardRequest;
use App\BusinessModules\Features\Budgeting\Services\ProjectPortfolioDashboardService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

use function trans_message;

final class ProjectPortfolioDashboardController extends BudgetingAdminController
{
    public function __construct(
        private readonly ProjectPortfolioDashboardService $service,
    ) {
    }

    public function show(ProjectPortfolioDashboardRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->dashboard($this->inputWithOrganization($request), $this->user($request)),
                trans_message('budgeting.project_portfolio_dashboard.loaded'),
            );
        } catch (DomainException|InvalidArgumentException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('budgeting.project_portfolio_dashboard.api_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->attributes->get('current_organization_id'),
                'path' => $request->path(),
                'exception_class' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('budgeting.project_portfolio_dashboard.load_error'), 500);
        }
    }

    private function inputWithOrganization(Request $request): array
    {
        $validated = $request instanceof ProjectPortfolioDashboardRequest ? $request->validated() : $request->all();
        $currentOrganizationId = $request->attributes->get('current_organization_id')
            ?? $validated['current_organization_id']
            ?? null;

        if ($currentOrganizationId === null || (int) $currentOrganizationId <= 0) {
            throw new DomainException(trans_message('budgeting.organization_context_missing'));
        }

        $requestedOrganizationId = $validated['organization_id'] ?? null;

        if ($requestedOrganizationId !== null && (int) $requestedOrganizationId !== (int) $currentOrganizationId) {
            throw new DomainException(trans_message('budgeting.project_portfolio_dashboard.errors.organization_mismatch'));
        }

        $validated['organization_id'] = (int) $currentOrganizationId;

        return $validated;
    }
}
