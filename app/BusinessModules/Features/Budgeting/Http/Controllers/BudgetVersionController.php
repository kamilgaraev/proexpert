<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Http\Controllers;

use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetVersionCloneRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetVersionRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetVersionUpdateRequest;
use App\BusinessModules\Features\Budgeting\Http\Requests\BudgetWorkflowRequest;
use App\BusinessModules\Features\Budgeting\Services\BudgetVersionService;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class BudgetVersionController extends BudgetingAdminController
{
    public function __construct(private readonly BudgetVersionService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $result = $this->service->index($this->user($request), $request->query());

            return AdminResponse::paginated(
                $result['paginator']->items(),
                $this->paginationMeta($result['paginator']),
                trans_message('budgeting.versions.loaded'),
                200,
                $result['summary']
            );
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function store(BudgetVersionRequest $request): JsonResponse
    {
        try {
            $version = $this->service->store($this->user($request), $request->validated());

            return AdminResponse::success($this->service->versionToArray($version, false), trans_message('budgeting.versions.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function show(Request $request, string $versionUuid): JsonResponse
    {
        try {
            $version = $this->service->findVersion($this->user($request), $versionUuid);

            return AdminResponse::success($this->service->versionToArray($version), trans_message('budgeting.versions.loaded'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function update(BudgetVersionUpdateRequest $request, string $versionUuid): JsonResponse
    {
        try {
            $version = $this->service->update($this->user($request), $versionUuid, $request->validated());

            return AdminResponse::success($this->service->versionToArray($version, false), trans_message('budgeting.versions.updated'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function destroy(Request $request, string $versionUuid): JsonResponse
    {
        try {
            $version = $this->service->destroy($this->user($request), $versionUuid);

            return AdminResponse::success($this->service->versionToArray($version, false), trans_message('budgeting.versions.archived'));
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function cloneVersion(BudgetVersionCloneRequest $request, string $versionUuid): JsonResponse
    {
        try {
            $version = $this->service->cloneVersion($this->user($request), $versionUuid, $request->validated());

            return AdminResponse::success($this->service->versionToArray($version, false), trans_message('budgeting.versions.created'), 201);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }

    public function submit(BudgetWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'submit', trans_message('budgeting.workflow.submitted'));
    }

    public function approve(BudgetWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'approve', trans_message('budgeting.workflow.approved'));
    }

    public function reject(BudgetWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'reject', trans_message('budgeting.workflow.rejected'));
    }

    public function activate(BudgetWorkflowRequest $request, string $versionUuid): JsonResponse
    {
        return $this->workflow($request, $versionUuid, 'activate', trans_message('budgeting.workflow.activated'));
    }

    private function workflow(BudgetWorkflowRequest $request, string $versionUuid, string $action, string $message): JsonResponse
    {
        try {
            $version = $this->service->transition(
                $this->user($request),
                $versionUuid,
                $action,
                $request->validated()['comment'] ?? null
            );

            return AdminResponse::success($this->service->versionToArray($version, false), $message);
        } catch (DomainException $exception) {
            return $this->domainError($exception);
        } catch (Throwable $exception) {
            return $this->unexpectedError($exception, $request);
        }
    }
}
