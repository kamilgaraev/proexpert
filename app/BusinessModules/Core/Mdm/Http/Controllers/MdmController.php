<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Models\MdmChangeLog;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmDuplicateGroup;
use App\BusinessModules\Core\Mdm\Models\MdmImportBatch;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\BusinessModules\Core\Mdm\Models\MdmRelationship;
use App\BusinessModules\Core\Mdm\Services\MdmDuplicateDetectionService;
use App\BusinessModules\Core\Mdm\Services\MdmChangeRequestService;
use App\BusinessModules\Core\Mdm\Services\MdmEntityRegistry;
use App\BusinessModules\Core\Mdm\Services\MdmImportService;
use App\BusinessModules\Core\Mdm\Services\MdmFileImportParser;
use App\BusinessModules\Core\Mdm\Services\MdmMergeService;
use App\BusinessModules\Core\Mdm\Services\MdmQualityPolicyService;
use App\BusinessModules\Core\Mdm\Services\MdmRecordService;
use App\BusinessModules\Core\Mdm\Services\MdmRelationshipService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class MdmController extends Controller
{
    public function __construct(
        private readonly MdmEntityRegistry $registry,
        private readonly MdmRecordService $recordService,
        private readonly MdmDuplicateDetectionService $duplicateDetectionService,
        private readonly MdmRelationshipService $relationshipService,
        private readonly MdmImportService $importService,
        private readonly MdmChangeRequestService $changeRequestService,
        private readonly MdmMergeService $mergeService,
        private readonly MdmQualityPolicyService $qualityPolicyService,
        private readonly MdmFileImportParser $fileImportParser
    ) {
    }

    public function entities(): JsonResponse
    {
        try {
            return AdminResponse::success($this->registry->all());
        } catch (Throwable $e) {
            Log::error('MDM entities failed', ['error' => $e->getMessage()]);

            return AdminResponse::error(trans_message('mdm.errors.entities_failed'), 500);
        }
    }

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);

            return AdminResponse::success([
                'entities' => $this->recordService->summary($organizationId),
                'duplicates_open' => MdmDuplicateGroup::query()
                    ->where('organization_id', $organizationId)
                    ->where('status', 'open')
                    ->count(),
                'relationships' => MdmRelationship::query()
                    ->where('organization_id', $organizationId)
                    ->count(),
                'imports' => MdmImportBatch::query()
                    ->where('organization_id', $organizationId)
                    ->latest()
                    ->limit(5)
                    ->get(),
            ]);
        } catch (Throwable $e) {
            Log::error('MDM dashboard failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.dashboard_failed'), 500);
        }
    }

    public function records(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $query = MdmRecord::query()->where('organization_id', $organizationId);

            if ($request->filled('entity_type')) {
                $query->where('entity_type', (string) $request->query('entity_type'));
            }

            if ($request->filled('status')) {
                $query->where('status', (string) $request->query('status'));
            }

            if ($request->filled('q')) {
                $query->where('display_name', 'like', '%' . $request->query('q') . '%');
            }

            $perPage = min(max((int) $request->query('per_page', 25), 1), 100);
            $records = $query->orderByDesc('updated_at')->paginate($perPage);

            return AdminResponse::paginated(
                $records->items(),
                [
                    'current_page' => $records->currentPage(),
                    'per_page' => $records->perPage(),
                    'total' => $records->total(),
                    'last_page' => $records->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            Log::error('MDM records failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.records_failed'), 500);
        }
    }

    public function sync(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['nullable', 'string', Rule::in(array_keys($this->registry->all()))],
            ]);
            $result = $this->recordService->syncOrganization(
                $this->organizationId($request),
                $validated['entity_type'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success($result, trans_message('mdm.messages.synced'));
        } catch (Throwable $e) {
            Log::error('MDM sync failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.sync_failed'), 500);
        }
    }

    public function scanDuplicates(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['nullable', 'string', Rule::in(array_keys($this->registry->all()))],
            ]);
            $result = $this->duplicateDetectionService->scanOrganization(
                $this->organizationId($request),
                $validated['entity_type'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success($result, trans_message('mdm.messages.duplicates_scanned'));
        } catch (Throwable $e) {
            Log::error('MDM duplicate scan failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.duplicates_failed'), 500);
        }
    }

    public function duplicates(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $groups = MdmDuplicateGroup::query()
                ->with('members')
                ->where('organization_id', $organizationId)
                ->when($request->filled('entity_type'), static fn ($query) => $query->where('entity_type', $request->query('entity_type')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->query('status')))
                ->orderByDesc('updated_at')
                ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

            return AdminResponse::paginated($groups->items(), [
                'current_page' => $groups->currentPage(),
                'per_page' => $groups->perPage(),
                'total' => $groups->total(),
                'last_page' => $groups->lastPage(),
            ]);
        } catch (Throwable $e) {
            Log::error('MDM duplicate list failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.duplicates_failed'), 500);
        }
    }

    public function resolveDuplicate(Request $request, MdmDuplicateGroup $group): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => ['required', 'string', Rule::in(['resolved', 'rejected'])],
                'master_entity_id' => ['nullable', 'integer'],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            if ((int) $group->organization_id !== $this->organizationId($request)) {
                return AdminResponse::error(trans_message('mdm.errors.not_found'), 404);
            }

            $resolved = $this->duplicateDetectionService->resolve(
                $group,
                $validated['decision'],
                $validated['master_entity_id'] ?? null,
                $request->user()?->id,
                $validated['note'] ?? null
            );

            return AdminResponse::success($resolved->load('members'), trans_message('mdm.messages.duplicate_resolved'));
        } catch (Throwable $e) {
            Log::error('MDM duplicate resolve failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.duplicate_resolve_failed'), 500);
        }
    }

    public function archive(Request $request, string $entityType, int $entityId): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reason' => ['nullable', 'string', 'max:1000'],
            ]);

            $record = $this->recordService->archive(
                $entityType,
                $entityId,
                $this->organizationId($request),
                $request->user()?->id,
                $validated['reason'] ?? null
            );

            return AdminResponse::success($record, trans_message('mdm.messages.archived'));
        } catch (Throwable $e) {
            Log::error('MDM archive failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.archive_failed'), 500);
        }
    }

    public function relationships(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $relationships = MdmRelationship::query()
                ->where('organization_id', $organizationId)
                ->when($request->filled('source_type'), static fn ($query) => $query->where('source_type', $request->query('source_type')))
                ->when($request->filled('target_type'), static fn ($query) => $query->where('target_type', $request->query('target_type')))
                ->orderByDesc('updated_at')
                ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

            return AdminResponse::paginated($relationships->items(), [
                'current_page' => $relationships->currentPage(),
                'per_page' => $relationships->perPage(),
                'total' => $relationships->total(),
                'last_page' => $relationships->lastPage(),
            ]);
        } catch (Throwable $e) {
            Log::error('MDM relationships failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.relationships_failed'), 500);
        }
    }

    public function syncRelationships(Request $request): JsonResponse
    {
        try {
            $result = $this->relationshipService->syncOrganization($this->organizationId($request));

            return AdminResponse::success($result, trans_message('mdm.messages.relationships_synced'));
        } catch (Throwable $e) {
            Log::error('MDM relationship sync failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.relationships_failed'), 500);
        }
    }

    public function history(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $history = MdmChangeLog::query()
                ->where('organization_id', $organizationId)
                ->when($request->filled('entity_type'), static fn ($query) => $query->where('entity_type', $request->query('entity_type')))
                ->when($request->filled('entity_id'), static fn ($query) => $query->where('entity_id', (int) $request->query('entity_id')))
                ->orderByDesc('created_at')
                ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

            return AdminResponse::paginated($history->items(), [
                'current_page' => $history->currentPage(),
                'per_page' => $history->perPage(),
                'total' => $history->total(),
                'last_page' => $history->lastPage(),
            ]);
        } catch (Throwable $e) {
            Log::error('MDM history failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.history_failed'), 500);
        }
    }

    public function importPreview(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
                'rows' => ['required', 'array'],
                'source' => ['nullable', 'string', 'max:120'],
            ]);

            $batch = $this->importService->preview(
                $this->organizationId($request),
                $validated['entity_type'],
                $validated['rows'],
                $request->user()?->id,
                $validated['source'] ?? 'manual'
            );

            return AdminResponse::success($batch, trans_message('mdm.messages.import_preview_ready'));
        } catch (Throwable $e) {
            Log::error('MDM import preview failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.import_failed'), 500);
        }
    }

    public function importApply(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
                'rows' => ['required', 'array'],
                'source' => ['nullable', 'string', 'max:120'],
            ]);

            $batch = $this->importService->apply(
                $this->organizationId($request),
                $validated['entity_type'],
                $validated['rows'],
                $request->user()?->id,
                $validated['source'] ?? 'manual'
            );

            return AdminResponse::success($batch, trans_message('mdm.messages.import_applied'));
        } catch (Throwable $e) {
            Log::error('MDM import apply failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.import_failed'), 500);
        }
    }

    public function changeRequests(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $requests = MdmChangeRequest::query()
                ->where('organization_id', $organizationId)
                ->when($request->filled('entity_type'), static fn ($query) => $query->where('entity_type', $request->query('entity_type')))
                ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->query('status')))
                ->orderByDesc('created_at')
                ->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

            return AdminResponse::paginated($requests->items(), [
                'current_page' => $requests->currentPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
                'last_page' => $requests->lastPage(),
            ]);
        } catch (Throwable $e) {
            Log::error('MDM change requests failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.change_requests_failed'), 500);
        }
    }

    public function submitChangeRequest(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
                'entity_id' => ['nullable', 'integer'],
                'action' => ['required', 'string', Rule::in(['create', 'update'])],
                'proposed_values' => ['required', 'array'],
            ]);

            $changeRequest = $this->changeRequestService->submit(
                $this->organizationId($request),
                $validated['entity_type'],
                $validated['action'],
                $validated['proposed_values'],
                $validated['entity_id'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success($changeRequest, trans_message('mdm.messages.change_request_submitted'), 201);
        } catch (Throwable $e) {
            Log::error('MDM change request submit failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.change_request_submit_failed'), 500);
        }
    }

    public function reviewChangeRequest(Request $request, MdmChangeRequest $changeRequest): JsonResponse
    {
        try {
            $validated = $request->validate([
                'decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
                'note' => ['nullable', 'string', 'max:1000'],
            ]);

            if ((int) $changeRequest->organization_id !== $this->organizationId($request)) {
                return AdminResponse::error(trans_message('mdm.errors.not_found'), 404);
            }

            $reviewed = $validated['decision'] === 'approved'
                ? $this->changeRequestService->approve($changeRequest, $request->user()?->id, $validated['note'] ?? null)
                : $this->changeRequestService->reject($changeRequest, $request->user()?->id, $validated['note'] ?? null);

            return AdminResponse::success($reviewed, trans_message('mdm.messages.change_request_reviewed'));
        } catch (Throwable $e) {
            Log::error('MDM change request review failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.change_request_review_failed'), 500);
        }
    }

    public function assignOwner(Request $request, MdmRecord $record): JsonResponse
    {
        try {
            $validated = $request->validate([
                'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            ]);

            if ((int) $record->organization_id !== $this->organizationId($request)) {
                return AdminResponse::error(trans_message('mdm.errors.not_found'), 404);
            }

            $updated = $this->changeRequestService->assignOwner(
                $record,
                $validated['owner_user_id'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success($updated, trans_message('mdm.messages.owner_assigned'));
        } catch (Throwable $e) {
            Log::error('MDM owner assign failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.owner_assign_failed'), 500);
        }
    }

    public function mergePlan(Request $request, MdmDuplicateGroup $group): JsonResponse
    {
        try {
            $validated = $request->validate([
                'master_entity_id' => ['required', 'integer'],
            ]);

            if ((int) $group->organization_id !== $this->organizationId($request)) {
                return AdminResponse::error(trans_message('mdm.errors.not_found'), 404);
            }

            $run = $this->mergeService->plan($group->load('members'), (int) $validated['master_entity_id']);

            return AdminResponse::success($run, trans_message('mdm.messages.merge_plan_ready'));
        } catch (Throwable $e) {
            Log::error('MDM merge plan failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.merge_failed'), 500);
        }
    }

    public function mergeApply(Request $request, MdmDuplicateGroup $group): JsonResponse
    {
        try {
            $validated = $request->validate([
                'master_entity_id' => ['required', 'integer'],
            ]);

            if ((int) $group->organization_id !== $this->organizationId($request)) {
                return AdminResponse::error(trans_message('mdm.errors.not_found'), 404);
            }

            $run = $this->mergeService->apply($group->load('members'), (int) $validated['master_entity_id'], $request->user()?->id);

            return AdminResponse::success($run, trans_message('mdm.messages.merge_applied'));
        } catch (Throwable $e) {
            Log::error('MDM merge apply failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.merge_failed'), 500);
        }
    }

    public function qualityPolicies(Request $request): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $policies = collect(array_keys($this->registry->all()))
                ->map(fn (string $entityType): array => array_merge(
                    ['entity_type' => $entityType],
                    $this->qualityPolicyService->get($organizationId, $entityType)
                ))
                ->values()
                ->all();

            return AdminResponse::success($policies);
        } catch (Throwable $e) {
            Log::error('MDM quality policies failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.quality_policies_failed'), 500);
        }
    }

    public function updateQualityPolicy(Request $request, string $entityType): JsonResponse
    {
        try {
            abort_unless(array_key_exists($entityType, $this->registry->all()), 404);

            $validated = $request->validate([
                'required_fields' => ['required', 'array'],
                'required_fields.*' => ['string'],
                'field_weights' => ['required', 'array'],
                'validation_rules' => ['nullable', 'array'],
                'min_acceptable_score' => ['required', 'integer', 'min:0', 'max:100'],
            ]);

            $policy = $this->qualityPolicyService->upsert($this->organizationId($request), $entityType, $validated);

            return AdminResponse::success($policy, trans_message('mdm.messages.quality_policy_saved'));
        } catch (Throwable $e) {
            Log::error('MDM quality policy update failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.quality_policy_save_failed'), 500);
        }
    }

    public function fileImportPreview(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
                'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
                'mapping' => ['nullable', 'array'],
            ]);

            $rows = $this->fileImportParser->parse($validated['file']->getRealPath(), $validated['mapping'] ?? []);
            $batch = $this->importService->preview(
                $this->organizationId($request),
                $validated['entity_type'],
                $rows,
                $request->user()?->id,
                'file'
            );

            return AdminResponse::success($batch, trans_message('mdm.messages.import_preview_ready'));
        } catch (Throwable $e) {
            Log::error('MDM file import preview failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.import_failed'), 500);
        }
    }

    public function fileImportApply(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
                'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls'],
                'mapping' => ['nullable', 'array'],
            ]);

            $rows = $this->fileImportParser->parse($validated['file']->getRealPath(), $validated['mapping'] ?? []);
            $batch = $this->importService->apply(
                $this->organizationId($request),
                $validated['entity_type'],
                $rows,
                $request->user()?->id,
                'file'
            );

            return AdminResponse::success($batch, trans_message('mdm.messages.import_applied'));
        } catch (Throwable $e) {
            Log::error('MDM file import apply failed', ['error' => $e->getMessage(), 'user_id' => $request->user()?->id]);

            return AdminResponse::error(trans_message('mdm.errors.import_failed'), 500);
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) ($request->attributes->get('current_organization_id') ?? $request->user()?->current_organization_id);
    }
}
