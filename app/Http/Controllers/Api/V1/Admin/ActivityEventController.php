<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTOs\Activity\ActivityEventFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Activity\ActivityEventExportRequest;
use App\Http\Requests\Api\V1\Admin\Activity\ActivityEventIndexRequest;
use App\Http\Resources\Api\V1\Admin\Activity\ActivityEventDetailResource;
use App\Http\Resources\Api\V1\Admin\Activity\ActivityEventResource;
use App\Http\Responses\AdminResponse;
use App\Services\Activity\ActivityEventExportService;
use App\Services\Activity\ActivityEventQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function trans_message;

class ActivityEventController extends Controller
{
    public function __construct(
        private readonly ActivityEventQueryService $queryService,
        private readonly ActivityEventExportService $exportService,
    ) {}

    public function index(ActivityEventIndexRequest $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $events = $this->queryService->paginate($organizationId, ActivityEventFilters::fromRequest($request));

            return AdminResponse::paginated(
                ActivityEventResource::collection($events->getCollection()),
                [
                    'current_page' => $events->currentPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                    'last_page' => $events->lastPage(),
                ],
                trans_message('activity.events_loaded')
            );
        } catch (Throwable $e) {
            Log::error('activity.events.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('activity.events_load_error'), 500);
        }
    }

    public function show(Request $request, int $event): JsonResponse
    {
        try {
            $activityEvent = $this->queryService->findForOrganization($this->organizationId($request), $event);

            if ($activityEvent === null) {
                return AdminResponse::error(trans_message('activity.event_not_found'), 404);
            }

            return AdminResponse::success(new ActivityEventDetailResource($activityEvent), trans_message('activity.event_loaded'));
        } catch (Throwable $e) {
            Log::error('activity.events.show_error', [
                'user_id' => $request->user()?->id,
                'event_id' => $event,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('activity.event_load_error'), 500);
        }
    }

    public function summary(ActivityEventIndexRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->queryService->summary($this->organizationId($request), ActivityEventFilters::fromRequest($request)),
                trans_message('activity.summary_loaded')
            );
        } catch (Throwable $e) {
            Log::error('activity.summary.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('activity.summary_load_error'), 500);
        }
    }

    public function actors(ActivityEventIndexRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->queryService->actors($this->organizationId($request), ActivityEventFilters::fromRequest($request)),
                trans_message('activity.actors_loaded')
            );
        } catch (Throwable $e) {
            Log::error('activity.actors.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('activity.actors_load_error'), 500);
        }
    }

    public function export(ActivityEventExportRequest $request): StreamedResponse|JsonResponse
    {
        try {
            return $this->exportService->csv($this->organizationId($request), ActivityEventFilters::fromRequest($request));
        } catch (Throwable $e) {
            Log::error('activity.export.error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('activity.export_error'), 500);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
