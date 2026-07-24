<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Http\Requests\ArchiveMdmRecordRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\AssignMdmOwnerRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ListMdmHistoryRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ListMdmRecordsRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ListMdmRelationshipsRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\SyncMdmRequest;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Services\MdmChangeRequestService;
use App\BusinessModules\Core\Mdm\Services\MdmReadService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdmRecordsController extends MdmBaseController
{
    public function __construct(
        private readonly MdmReadService $readService,
        private readonly MdmRecordService $recordService,
        private readonly MdmRelationshipService $relationshipService,
        private readonly MdmChangeRequestService $changeRequestService
    ) {}

    public function entities(Request $request): JsonResponse
    {
        return $this->handle($request, 'MDM entities failed', 'mdm.errors.entities_failed', fn (): JsonResponse => AdminResponse::success($this->readService->entities()));
    }

    public function dashboard(Request $request): JsonResponse
    {
        return $this->handle($request, 'MDM dashboard failed', 'mdm.errors.dashboard_failed', fn (): JsonResponse => AdminResponse::success($this->readService->dashboard($this->organizationId($request))));
    }

    public function records(ListMdmRecordsRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM records failed', 'mdm.errors.records_failed', fn (): JsonResponse => $this->paginated($this->readService->records($request->organizationId(), $request->validated())));
    }

    public function record(Request $request, MdmRecord $mdmRecord): JsonResponse
    {
        return $this->handle($request, 'MDM record show failed', 'mdm.errors.records_failed', fn (): JsonResponse => AdminResponse::success($mdmRecord));
    }

    public function sync(SyncMdmRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM sync failed', 'mdm.errors.sync_failed', function () use ($request): JsonResponse {
            $result = $this->recordService->syncOrganization($request->organizationId(), $request->validated('entity_type'), $request->user()?->id);

            return AdminResponse::success($result, trans_message('mdm.messages.synced'));
        });
    }

    public function archive(ArchiveMdmRecordRequest $request, string $entityType, int $entityId): JsonResponse
    {
        return $this->handle($request, 'MDM archive failed', 'mdm.errors.archive_failed', function () use ($request, $entityType, $entityId): JsonResponse {
            $record = $this->recordService->archive($entityType, $entityId, $request->organizationId(), $request->user()?->id, $request->validated('reason'));

            return AdminResponse::success($record, trans_message('mdm.messages.archived'));
        });
    }

    public function relationships(ListMdmRelationshipsRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM relationships failed', 'mdm.errors.relationships_failed', fn (): JsonResponse => $this->paginated($this->readService->relationships($request->organizationId(), $request->validated())));
    }

    public function syncRelationships(Request $request): JsonResponse
    {
        return $this->handle($request, 'MDM relationship sync failed', 'mdm.errors.relationships_failed', function () use ($request): JsonResponse {
            $result = $this->relationshipService->syncOrganization($this->organizationId($request));

            return AdminResponse::success($result, trans_message('mdm.messages.relationships_synced'));
        });
    }

    public function history(ListMdmHistoryRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM history failed', 'mdm.errors.history_failed', fn (): JsonResponse => $this->paginated($this->readService->history($request->organizationId(), $request->validated())));
    }

    public function assignOwner(AssignMdmOwnerRequest $request, MdmRecord $mdmRecord): JsonResponse
    {
        return $this->handle($request, 'MDM owner assign failed', 'mdm.errors.owner_assign_failed', function () use ($request, $mdmRecord): JsonResponse {
            $updated = $this->changeRequestService->assignOwner($mdmRecord, $request->validated('owner_user_id'), $request->user()?->id);

            return AdminResponse::success($updated, trans_message('mdm.messages.owner_assigned'));
        });
    }
}
