<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\Pto;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Pto\ListPtoWorkQueueRequest;
use App\Http\Requests\Api\V1\Admin\Pto\UpsertPtoWorkspaceTaskRequest;
use App\Http\Responses\AdminResponse;
use App\Models\PtoWorkspaceTask;
use App\Services\Pto\PtoWorkspaceQuery;
use App\Services\Pto\PtoWorkspaceTaskSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class PtoWorkspaceController extends Controller
{
    public function __construct(
        private readonly PtoWorkspaceQuery $query,
        private readonly PtoWorkspaceTaskSync $sync,
    ) {
    }

    public function callAction($method, $parameters): JsonResponse
    {
        try {
            return parent::callAction($method, $parameters);
        } catch (BusinessLogicException $exception) {
            $status = in_array($exception->getCode(), [403, 404, 409, 422], true) ? $exception->getCode() : 422;

            return AdminResponse::error($exception->getMessage(), $status);
        } catch (ValidationException $exception) {
            return AdminResponse::error($exception->getMessage(), 422, $exception->errors());
        }
    }

    public function queue(ListPtoWorkQueueRequest $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $filters = $request->validated();
        $this->sync->syncAccessible($request->user(), $organizationId, $filters);
        $result = $this->query->workQueue($request->user(), $organizationId, $filters);

        return $this->page($result);
    }

    public function completeness(ListPtoWorkQueueRequest $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $filters = $request->validated();
        $this->sync->syncAccessible($request->user(), $organizationId, $filters);
        $result = $this->query->completeness($request->user(), $organizationId, $filters);

        return $this->page($result);
    }

    public function upsertTask(UpsertPtoWorkspaceTaskRequest $request): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $task = $this->sync->upsert($request->user(), $organizationId, $request->validated());

        return AdminResponse::success($this->taskPayload($task));
    }

    public function completeTask(PtoWorkspaceTask $task): JsonResponse
    {
        $request = request();
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $updated = $this->sync->complete($request->user(), $organizationId, $task);

        return AdminResponse::success($this->taskPayload($updated));
    }

    /**
     * @param  array{paginator: \Illuminate\Contracts\Pagination\LengthAwarePaginator, summary: array<string, mixed>}  $result
     */
    private function page(array $result): JsonResponse
    {
        $paginator = $result['paginator'];

        return AdminResponse::paginated($paginator->items(), [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ], summary: $result['summary']);
    }

    /**
     * @return array<string, mixed>
     */
    private function taskPayload(PtoWorkspaceTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'source_key' => $task->source_key,
            'title' => $task->title,
            'status' => $task->status,
            'origin' => $task->origin,
            'responsible_user_id' => $task->responsible_user_id ? (int) $task->responsible_user_id : null,
            'due_on' => $task->due_on?->format('Y-m-d'),
            'project_id' => (int) $task->project_id,
            'document_set_id' => $task->document_set_id ? (int) $task->document_set_id : null,
            'requirement_id' => $task->requirement_id ? (int) $task->requirement_id : null,
        ];
    }
}
