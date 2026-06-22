<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditExportService;
use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditQueryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ImmutableAudit\ImmutableAuditEventExportRequest;
use App\Http\Requests\Api\V1\Admin\ImmutableAudit\ImmutableAuditEventIndexRequest;
use App\Http\Requests\Api\V1\Admin\ImmutableAudit\ImmutableAuditIntegrityRequest;
use App\Http\Resources\Api\V1\Admin\ImmutableAudit\ImmutableAuditEventDetailResource;
use App\Http\Resources\Api\V1\Admin\ImmutableAudit\ImmutableAuditEventResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function trans_message;

final class ImmutableAuditController extends Controller
{
    public function __construct(
        private readonly ImmutableAuditQueryService $queryService,
        private readonly ImmutableAuditExportService $exportService,
    ) {}

    public function index(ImmutableAuditEventIndexRequest $request): JsonResponse
    {
        try {
            $filters = ImmutableAuditEventFilters::fromRequest($request, $this->organizationId($request));
            $events = $this->queryService->paginate($filters);

            return AdminResponse::paginated(
                ImmutableAuditEventResource::collection($events->getCollection()),
                [
                    'current_page' => $events->currentPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                    'last_page' => $events->lastPage(),
                ],
                trans_message('immutable_audit.events_loaded'),
                200,
                $this->queryService->summary($filters)
            );
        } catch (Throwable $e) {
            Log::error('immutable_audit.events.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('immutable_audit.events_load_error'), 500);
        }
    }

    public function show(Request $request, string $event): JsonResponse
    {
        try {
            $auditEvent = $this->queryService->findForOrganization($this->organizationId($request), $event);

            if ($auditEvent === null) {
                return AdminResponse::error(trans_message('immutable_audit.event_not_found'), 404);
            }

            return AdminResponse::success(
                new ImmutableAuditEventDetailResource($auditEvent),
                trans_message('immutable_audit.event_loaded')
            );
        } catch (Throwable $e) {
            Log::error('immutable_audit.events.show_error', [
                'user_id' => $request->user()?->id,
                'event_id' => $event,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('immutable_audit.event_load_error'), 500);
        }
    }

    public function integrity(ImmutableAuditIntegrityRequest $request): JsonResponse
    {
        try {
            $filters = ImmutableAuditEventFilters::fromRequest($request, $this->organizationId($request));

            return AdminResponse::success(
                $this->queryService->verifyChain($filters),
                trans_message('immutable_audit.integrity_loaded')
            );
        } catch (Throwable $e) {
            Log::error('immutable_audit.integrity.index_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('immutable_audit.integrity_load_error'), 500);
        }
    }

    public function eventIntegrity(Request $request, string $event): JsonResponse
    {
        try {
            $auditEvent = $this->queryService->findForOrganization($this->organizationId($request), $event);

            if ($auditEvent === null) {
                return AdminResponse::error(trans_message('immutable_audit.event_not_found'), 404);
            }

            return AdminResponse::success(
                $this->queryService->verifyEvent($auditEvent),
                trans_message('immutable_audit.integrity_loaded')
            );
        } catch (Throwable $e) {
            Log::error('immutable_audit.integrity.show_error', [
                'user_id' => $request->user()?->id,
                'event_id' => $event,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('immutable_audit.integrity_load_error'), 500);
        }
    }

    public function export(ImmutableAuditEventExportRequest $request): StreamedResponse|JsonResponse
    {
        try {
            $filters = ImmutableAuditEventFilters::fromRequest($request, $this->organizationId($request));

            return $this->exportService->csv($filters);
        } catch (Throwable $e) {
            Log::error('immutable_audit.events.export_error', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('immutable_audit.export_error'), 500);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }
}
