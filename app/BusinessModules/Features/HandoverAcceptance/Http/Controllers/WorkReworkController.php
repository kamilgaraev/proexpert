<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Http\Controllers;

use App\BusinessModules\Features\HandoverAcceptance\Http\Requests\StoreWorkReworkRequest;
use App\BusinessModules\Features\HandoverAcceptance\Http\Requests\SubmitWorkReworkRequest;
use App\BusinessModules\Features\HandoverAcceptance\Http\Requests\VerifyWorkReworkRequest;
use App\BusinessModules\Features\HandoverAcceptance\Http\Resources\WorkReworkResource;
use App\BusinessModules\Features\HandoverAcceptance\Models\AcceptanceScope;
use App\BusinessModules\Features\HandoverAcceptance\Models\WorkRework;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkException;
use App\BusinessModules\Features\HandoverAcceptance\Services\WorkReworkService;
use App\Exceptions\BusinessLogicException;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final readonly class WorkReworkController
{
    public function __construct(private WorkReworkService $service) {}

    public function index(Request $request, int $scope): JsonResponse
    {
        return $this->respond($request, function () use ($request, $scope): JsonResponse {
            $items = $this->service->listForScope($this->scope($request, $scope), (int) $request->user()->id);

            return AdminResponse::paginated(WorkReworkResource::collection($items->items()), [
                'current_page' => $items->currentPage(), 'per_page' => $items->perPage(),
                'total' => $items->total(), 'last_page' => $items->lastPage(),
            ]);
        });
    }

    public function store(StoreWorkReworkRequest $request, int $scope): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => AdminResponse::success(new WorkReworkResource(
            $this->service->create($this->scope($request, $scope), (int) $request->user()->id, $request->validated()),
        ), code: 201));
    }

    public function submit(SubmitWorkReworkRequest $request, int $rework): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => AdminResponse::success(new WorkReworkResource(
            $this->service->submit($this->find($request, $rework), (int) $request->user()->id, $request->validated()),
        )));
    }

    public function verify(VerifyWorkReworkRequest $request, int $rework): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => AdminResponse::success(new WorkReworkResource(
            $this->service->verify($this->find($request, $rework), (int) $request->user()->id, $request->validated()),
        )));
    }

    public function history(Request $request, int $rework): JsonResponse
    {
        return $this->respond($request, function () use ($request, $rework): JsonResponse {
            $events = $this->service->history($this->find($request, $rework), (int) $request->user()->id);
            $items = collect($events->items())->map(fn (object $event): array => [
                'id' => $event->id, 'action' => $event->action, 'actor_id' => $event->actor_id,
                'created_at' => $event->created_at,
                'comment' => json_decode($event->payload, true, 512, JSON_THROW_ON_ERROR)['comment'] ?? null,
                'result' => new WorkReworkResource((new WorkRework)->newFromBuilder(json_decode($event->result_snapshot, true, 512, JSON_THROW_ON_ERROR))),
            ]);

            return AdminResponse::paginated($items, ['current_page' => $events->currentPage(), 'per_page' => $events->perPage(), 'total' => $events->total(), 'last_page' => $events->lastPage()]);
        });
    }

    private function scope(Request $request, int $scope): AcceptanceScope
    {
        return AcceptanceScope::query()->where('organization_id', $this->organizationId($request))->findOrFail($scope);
    }

    private function find(Request $request, int $rework): WorkRework
    {
        return $this->service->findForActor($this->organizationId($request), (int) $request->user()->id, $rework);
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id', $request->user()?->current_organization_id);
    }

    private function respond(Request $request, callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (WorkReworkException $exception) {
            return AdminResponse::error(trans_message('work_rework.errors.'.$exception->reason), $exception->getCode(), extra: ['code' => $exception->reason]);
        } catch (ValidationException $exception) {
            return AdminResponse::error(trans_message('handover_acceptance.errors.validation_failed'), 422, $exception->errors());
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('work_rework.errors.not_found'), 404);
        } catch (BusinessLogicException $exception) {
            $status = in_array($exception->getCode(), [403, 404], true) ? $exception->getCode() : 409;

            return AdminResponse::error(trans_message($status === 404 ? 'work_rework.errors.not_found' : 'handover_acceptance.errors.forbidden'), $status);
        } catch (\Throwable $exception) {
            Log::error('work_rework.action_failed', ['actor_id' => $request->user()?->id, 'organization_id' => $this->organizationId($request), 'exception_class' => $exception::class]);

            return AdminResponse::error(trans_message('handover_acceptance.errors.action_failed'), 500);
        }
    }
}
