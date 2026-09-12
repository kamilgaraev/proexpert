<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Http\Controllers;

use App\BusinessModules\Features\DesignManagement\Http\Requests\StoreDesignProjectIssueRequest;
use App\BusinessModules\Features\DesignManagement\Http\Requests\DesignProjectIssueActionRequest;
use App\BusinessModules\Features\DesignManagement\Http\Resources\DesignProjectIssueResource;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\File;

final class DesignProjectIssueController extends Controller
{
    public function __construct(private readonly DesignProjectIssueService $issues) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        try {
            return AdminResponse::success(DesignProjectIssueResource::collection($this->issues->list($request->user(), $this->organizationId($request), $projectId, $request->only(['status', 'version_id']))));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function store(StoreDesignProjectIssueRequest $request, int $projectId): JsonResponse
    {
        try {
            return AdminResponse::success(new DesignProjectIssueResource($this->issues->create($request->user(), $this->organizationId($request), $projectId, $request->validated())), trans_message('design_issues.messages.created'), 201);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    public function show(Request $request, int $issueId): JsonResponse
    {
        $issue = $this->issues->find($request->user(), $this->organizationId($request), $issueId);

        return $issue === null ? AdminResponse::error(trans_message('design_issues.errors.not_found'), 404) : AdminResponse::success(new DesignProjectIssueResource($issue));
    }

    public function assign(DesignProjectIssueActionRequest $request, int $issueId): JsonResponse
    {
        return $this->action($request, $issueId, ['assignee_id' => ['required', 'integer'], 'comment' => ['nullable', 'string', 'max:1000']], fn ($issue, $data) => $this->issues->assign($issue, $request->user(), (int) $data['assignee_id'], $data['comment'] ?? null));
    }

    public function resolve(DesignProjectIssueActionRequest $request, int $issueId): JsonResponse
    {
        return $this->action($request, $issueId, ['comment' => ['nullable', 'string', 'max:1000']], fn ($issue, $data) => $this->issues->resolve($issue, $request->user(), $data['comment'] ?? null));
    }

    public function verify(DesignProjectIssueActionRequest $request, int $issueId): JsonResponse
    {
        return $this->action($request, $issueId, ['accepted' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:1000']], fn ($issue, $data) => $this->issues->verify($issue, $request->user(), (bool) $data['accepted'], $data['comment'] ?? null));
    }

    public function blocking(DesignProjectIssueActionRequest $request, int $issueId): JsonResponse
    {
        return $this->action($request, $issueId, ['active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:1000']], fn ($issue, $data) => $this->issues->setBlocking($issue, $request->user(), (bool) $data['active'], $data['reason'] ?? null));
    }

    public function snapshot(DesignProjectIssueActionRequest $request, int $issueId): JsonResponse
    {
        return $this->action($request, $issueId, ['file' => ['required', File::image()->max(10 * 1024)]], fn ($issue, $data) => $this->issues->storeSnapshot($issue, $request->user(), $data['file']));
    }

    public function bimContext(Request $request, int $issueId): JsonResponse
    {
        try {
            $issue = $this->issues->find($request->user(), $this->organizationId($request), $issueId);
            if ($issue === null) {
                return AdminResponse::error(trans_message('design_issues.errors.not_found'), 404);
            }

            return AdminResponse::success($this->issues->bimContext($issue, $request->user()));
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    private function action(Request $request, int $issueId, array $rules, callable $operation): JsonResponse
    {
        try {
            $data = $request->validate($rules);
            $issue = $this->issues->find($request->user(), $this->organizationId($request), $issueId);
            if ($issue === null) {
                return AdminResponse::error(trans_message('design_issues.errors.not_found'), 404);
            }

            return AdminResponse::success(new DesignProjectIssueResource($this->issues->withRevision(
                $issue, (int) $request->input('expected_revision'), fn ($locked) => $operation($locked, $data),
            )));
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('design_issues.errors.validation_failed'), 422, $exception->errors());
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
