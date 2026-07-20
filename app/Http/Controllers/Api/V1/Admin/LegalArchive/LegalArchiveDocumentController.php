<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveDocumentIndexRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveLockRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveDocumentRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\LegalArchiveLifecycleService;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentCreateFailed;
use App\Services\LegalArchive\LegalDocumentCreateFailureReporter;
use App\Services\LegalArchive\LegalDocumentCreateInProgress;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

use function trans_message;

final class LegalArchiveDocumentController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalWorkflowActionResolver $actions,
        private readonly LegalArchiveLifecycleService $lifecycle,
        private readonly LegalDocumentCreateFailureReporter $createFailureReporter,
    ) {}

    public function index(LegalArchiveDocumentIndexRequest $request): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $filters = $request->validated();
            $documents = $this->registry->paginate($actor, $this->organizationId($request), $filters);
            foreach ($documents->getCollection() as $document) {
                $instance = $document->latestWorkflowInstance;
                $ready = $document->currentVersion !== null && (string) $document->currentVersion->processing_status === 'ready';
                $problemFlags = array_values(array_filter([
                    $ready ? null : 'no_ready_primary_version',
                    (int) $document->files_count > 0 ? null : 'no_files',
                    $instance?->due_at?->isPast() === true && $instance->status === 'in_progress' ? 'workflow_overdue' : null,
                ]));
                $document->setAttribute('api_workflow_summary', [
                    'status' => $instance?->status ?? 'not_started',
                    'current_steps' => $instance?->steps->where('status', 'active')->map(static fn ($step): array => [
                        'id' => (int) $step->id, 'label' => (string) $step->label, 'status' => (string) $step->status,
                    ])->values()->all() ?? [],
                    'available_action_details' => $instance?->status === 'in_progress'
                        ? [['action' => 'open_workflow', 'enabled' => true]]
                        : [['action' => 'submit', 'enabled' => $ready]],
                    'problem_flags' => $problemFlags,
                    'completeness' => [
                        'files' => (int) $document->files_count,
                        'signature_requests' => (int) $document->signature_requests_count,
                        'signatures' => (int) $document->signatures_count,
                    ],
                ]);
            }

            return AdminResponse::paginated(
                LegalArchiveDocumentResource::collection($documents->getCollection()),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
                trans_message('legal_archive.messages.documents_loaded'),
                summary: $this->registry->summary($actor, $this->organizationId($request), $filters),
            );
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'documents_index');
        }
    }

    public function store(StoreLegalArchiveDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->registry->create(
                $this->organizationId($request),
                (int) $this->actor($request)->id,
                $request->validated(),
                $request->file('file'),
            );

            return $this->etag(AdminResponse::success(
                new LegalArchiveDocumentResource($document),
                trans_message('legal_archive.messages.document_created'),
                201,
            ), $document);
        } catch (Throwable $error) {
            if ($error instanceof LegalDocumentCreateInProgress) {
                $document = $error->document->refresh();
                $leaseExpiresAt = $document->source_create_lease_expires_at;

                return $this->etag(AdminResponse::success(
                    new LegalArchiveDocumentResource($document),
                    trans_message('legal_archive.messages.source_create_in_progress'),
                    202,
                    [
                        'processing_status' => 'pending',
                        'operation_result' => 'in_progress',
                        'operation_id' => $document->create_operation_id,
                        'lease_expires_at' => $leaseExpiresAt?->toISOString(),
                        'retry_after' => $leaseExpiresAt === null ? 1 : max(1, now()->diffInSeconds($leaseExpiresAt, false)),
                    ],
                ), $document);
            }
            if ($error instanceof LegalDocumentCreateFailed) {
                $this->reportCreateFailure($request, $error, $error->document);

                $document = $error->document->refresh();

                return $this->etag(AdminResponse::success(
                    new LegalArchiveDocumentResource($document),
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_create_failed',
                        'operation_id' => $error->operationId(),
                        'retry_action' => $error->retryAction(),
                        'retry_document_id' => (int) $error->document->id,
                    ],
                ), $document);
            }
            if ($error instanceof LegalDocumentScanFailed) {
                $document = $this->registry->findForOrganization(
                    $this->organizationId($request),
                    (int) $error->version->document_id,
                );
                if ($document !== null) {
                    $this->reportCreateFailure($request, $error, $document);
                }

                $response = AdminResponse::success(
                    $document === null ? null : new LegalArchiveDocumentResource($document),
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_create_failed',
                        'operation_id' => $document?->create_operation_id,
                        'retry_action' => 'retry_upload',
                        'retry_document_id' => (int) $error->version->document_id,
                    ],
                );

                return $document === null ? $response : $this->etag($response, $document);
            }
            if ($error instanceof LegalDocumentFileRejected) {
                return AdminResponse::error(
                    trans_message('legal_archive.messages.validation_error'),
                    422,
                    ['file' => [trans_message('legal_archive.messages.file_rejected')]],
                );
            }

            return $this->failure($error, $request, 'document_store');
        }
    }

    private function reportCreateFailure(Request $request, Throwable $failure, \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument $document): void
    {
        $original = $failure instanceof LegalDocumentCreateFailed ? ($failure->getPrevious() ?? $failure) : $failure;
        $operationId = is_string($document->create_operation_id) && $document->create_operation_id !== ''
            ? $document->create_operation_id
            : 'document-'.(string) $document->id;
        $this->createFailureReporter->report(
            failure: $original,
            organizationId: (int) $document->organization_id,
            actorId: $request->user() === null ? null : (int) $request->user()->id,
            documentId: (int) $document->id,
            operationId: $operationId,
        );
    }

    public function show(Request $request, string $document): JsonResponse
    {
        try {
            $found = $this->registry->findForAuthorization((int) $document);
            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $this->actor($request);
            $this->access->authorize($actor, $found, 'view');
            $found->setAttribute('api_workflow_summary', $this->actions->for($actor, $found)->toArray());

            return $this->etag(AdminResponse::success(
                new LegalArchiveDocumentResource($found),
                trans_message('legal_archive.messages.document_loaded'),
            ), $found);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_show', ['document_id' => $document]);
        }
    }

    public function update(UpdateLegalArchiveDocumentRequest $request, string $document): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $document);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $found, 'legal_archive.update');
            $updated = $this->registry->update($found, $this->organizationId($request), (int) $actor->id, $request->validated());

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_updated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_update', ['document_id' => $document]);
        }
    }

    public function archive(LegalArchiveLockRequest $request, string $document): JsonResponse
    {
        try {
            $updated = $this->lifecycle->archive($this->requiredDocument($request, $document), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_archived')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_archive', ['document_id' => $document]);
        }
    }

    public function restore(LegalArchiveLockRequest $request, string $document): JsonResponse
    {
        try {
            $updated = $this->lifecycle->restore($this->requiredDocument($request, $document), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_restored')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_restore', ['document_id' => $document]);
        }
    }

    public function activate(LegalArchiveLockRequest $request, string $document): JsonResponse
    {
        try {
            $updated = $this->lifecycle->activate($this->requiredDocument($request, $document), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_activated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_activate', ['document_id' => $document]);
        }
    }

    public function timeline(Request $request, string $document): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $document);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $found, 'legal_archive.audit.view');
            $events = ImmutableAuditEvent::query()
                ->where('organization_id', (int) $found->organization_id)
                ->where('domain', 'legal_document')
                ->where('subject_id', (string) $found->id)
                ->orderByDesc('sequence_id')
                ->paginate(max(10, min((int) $request->integer('per_page', 50), 100)));

            return AdminResponse::paginated($events->items(), [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ], trans_message('legal_archive.messages.timeline_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_timeline', ['document_id' => $document]);
        }
    }

    public function availableActions(Request $request, string $document): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $document);

            return AdminResponse::success($this->actions->for($this->actor($request), $found)->toArray(), trans_message('legal_archive.messages.available_actions_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_actions', ['document_id' => $document]);
        }
    }

    public function recoveryIndex(Request $request): JsonResponse
    {
        try {
            $documents = $this->registry->paginateRecoveries($this->actor($request), $this->organizationId($request), (int) $request->integer('per_page', 20));

            return AdminResponse::paginated(LegalArchiveDocumentResource::collection($documents->getCollection()), [
                'current_page' => $documents->currentPage(), 'per_page' => $documents->perPage(),
                'total' => $documents->total(), 'last_page' => $documents->lastPage(),
            ], trans_message('legal_archive.messages.create_recoveries_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_recovery_index');
        }
    }

    public function recoverCreate(RecoverLegalArchiveDocumentRequest $request, string $operation): JsonResponse
    {
        try {
            $document = $this->registry->recoverCreate($this->actor($request), $this->organizationId($request), $operation, $request->file('file'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($document), trans_message('legal_archive.messages.create_recovery_completed')), $document);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_recovery', ['operation_id' => $operation]);
        }
    }

    private function requiredDocument(Request $request, string $id): \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument
    {
        $document = $this->registry->findForOrganization($this->organizationId($request), (int) $id);
        if ($document === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $document;
    }
}
