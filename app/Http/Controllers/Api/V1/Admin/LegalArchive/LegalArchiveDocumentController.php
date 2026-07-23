<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Models\ImmutableAuditEvent;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveDocumentIndexRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveLockRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalDocumentObligationRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Editor\LegalDocumentEditorAvailability;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\LegalArchiveLifecycleService;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentCreateFailed;
use App\Services\LegalArchive\LegalDocumentCreateFailureReporter;
use App\Services\LegalArchive\LegalDocumentCreateInProgress;
use App\Services\LegalArchive\Obligations\LegalDocumentObligationExecutionService;
use App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Throwable;

use function trans_message;

final class LegalArchiveDocumentController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalWorkflowActionResolver $actions,
        private readonly LegalDocumentEditorAvailability $editorAvailability,
        private readonly LegalArchiveLifecycleService $lifecycle,
        private readonly LegalDocumentCreateFailureReporter $createFailureReporter,
        private readonly AuthorizationService $authorization,
        private readonly LegalDocumentObligationExecutionService $obligationExecution,
    ) {}

    public function index(LegalArchiveDocumentIndexRequest $request): JsonResponse
    {
        try {
            $actor = $this->actor($request);
            $filters = $request->validated();
            $documents = $this->registry->paginate($actor, $this->organizationId($request), $filters);
            $workflowSummaries = $this->actions->forMany($actor, $documents->getCollection());
            foreach ($documents->getCollection() as $document) {
                $workflowSummary = $workflowSummaries[(int) $document->id]->toArray()['workflow_summary'];
                if ((int) $document->files_count < 1) {
                    $workflowSummary['problem_flags'][] = 'no_files';
                    $workflowSummary['problem_flags'] = array_values(array_unique($workflowSummary['problem_flags']));
                }
                $document->setAttribute('api_workflow_summary', [
                    ...$workflowSummary,
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
                $failureCode = $error->failureCode();
                $messageKey = match ($failureCode) {
                    'malware_detected' => 'legal_archive.messages.document_file_malware_detected',
                    'scanner_unavailable' => 'legal_archive.messages.document_file_scan_unavailable',
                    default => 'legal_archive.messages.document_file_processing_failed',
                };
                $document = $this->registry->findForOrganization(
                    $this->organizationId($request),
                    (int) $error->version->document_id,
                );
                if ($document !== null) {
                    $this->reportCreateFailure($request, $error, $document);
                }

                $response = AdminResponse::success(
                    $document === null ? null : new LegalArchiveDocumentResource($document),
                    trans_message($messageKey),
                    202,
                    [
                        'processing_status' => 'failed',
                        'processing_failure_code' => $failureCode,
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
        $original = $failure instanceof LegalDocumentCreateFailed || $failure instanceof LegalDocumentScanFailed
            ? ($failure->getPrevious() ?? $failure)
            : $failure;
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

    public function show(Request $request, string $legalDocument): JsonResponse
    {
        try {
            $found = $this->registry->findForAuthorization((int) $legalDocument);
            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $this->actor($request);
            $this->access->authorize($actor, $found, 'view');
            $summary = $this->actions->forMany($actor, collect([$found]))[(int) $found->id];
            $found->setAttribute(
                'api_workflow_summary',
                $summary->toArray()['workflow_summary'],
            );
            $found->setAttribute(
                'api_editor_current_version_editable',
                $this->editorAvailability->currentVersionEditable($found),
            );

            return $this->etag(AdminResponse::success(
                new LegalArchiveDocumentResource($found),
                trans_message('legal_archive.messages.document_loaded'),
            ), $found);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_show', ['document_id' => $legalDocument]);
        }
    }

    public function showForContract(Request $request, int $project, int $contract, string $legalDocument): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $linkedContract = Contract::query()->whereKey($contract)->where('project_id', $project)
                ->where('organization_id', $organizationId)->first();

            if ($linkedContract === null || (int) $linkedContract->legal_archive_document_id !== (int) $legalDocument) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }

            $found = $this->registry->findForAuthorization((int) $legalDocument);
            if ($found === null || (int) $found->organization_id !== $organizationId || (int) $found->primary_project_id !== $project) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $this->actor($request);
            $this->access->authorize($actor, $found, 'view');
            $summary = $this->actions->forMany($actor, collect([$found]))[(int) $found->id];
            $found->setAttribute(
                'api_workflow_summary',
                $summary->toArray()['workflow_summary'],
            );
            $found->setAttribute(
                'api_editor_current_version_editable',
                $this->editorAvailability->currentVersionEditable($found),
            );

            return $this->etag(AdminResponse::success(
                new LegalArchiveDocumentResource($found),
                trans_message('legal_archive.messages.document_loaded'),
            ), $found);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'contract_document_show', [
                'project_id' => $project,
                'contract_id' => $contract,
                'document_id' => $legalDocument,
            ]);
        }
    }

    public function update(UpdateLegalArchiveDocumentRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $legalDocument);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $found, 'legal_archive.update');
            $this->assertLinkedContractUpdateAllowed($actor, $found);
            $updated = $this->registry->update($found, $this->organizationId($request), (int) $actor->id, $request->validated());

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_updated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_update', ['document_id' => $legalDocument]);
        }
    }

    private function assertLinkedContractUpdateAllowed(User $actor, \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument $document): void
    {
        $contracts = Contract::query()
            ->where('organization_id', (int) $document->organization_id)
            ->where('legal_archive_document_id', (int) $document->id)
            ->get(['project_id']);

        foreach ($contracts as $contract) {
            if (! $this->authorization->can($actor, 'contracts.edit', [
                'organization_id' => (int) $document->organization_id,
                'project_id' => (int) $contract->project_id,
            ])) {
                throw (new AuthorizationException)->withStatus(403);
            }
        }
    }

    public function archive(LegalArchiveLockRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $updated = $this->lifecycle->archive($this->requiredDocument($request, $legalDocument), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_archived')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_archive', ['document_id' => $legalDocument]);
        }
    }

    public function restore(LegalArchiveLockRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $updated = $this->lifecycle->restore($this->requiredDocument($request, $legalDocument), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_restored')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_restore', ['document_id' => $legalDocument]);
        }
    }

    public function activate(LegalArchiveLockRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $updated = $this->lifecycle->activate($this->requiredDocument($request, $legalDocument), $this->actor($request), (int) $request->validated('lock_version'));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentResource($updated), trans_message('legal_archive.messages.document_activated')), $updated);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_activate', ['document_id' => $legalDocument]);
        }
    }

    public function updateObligation(UpdateLegalDocumentObligationRequest $request, string $legalDocument, int $obligation): JsonResponse
    {
        try {
            $document = $this->requiredDocument($request, $legalDocument);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $document, 'legal_archive.update');
            $this->assertLinkedContractUpdateAllowed($actor, $document);
            $updated = $this->obligationExecution->update($document, $obligation, $actor, $request->validated());

            return AdminResponse::success([
                'id' => (int) $updated->id,
                'status' => (string) $updated->status,
                'completed_at' => $updated->completed_at?->toISOString(),
            ], trans_message('legal_archive.messages.obligation_updated'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_obligation_update', ['document_id' => $legalDocument, 'obligation_id' => $obligation]);
        }
    }

    public function timeline(Request $request, string $legalDocument): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $legalDocument);
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
            return $this->failure($error, $request, 'document_timeline', ['document_id' => $legalDocument]);
        }
    }

    public function availableActions(Request $request, string $legalDocument): JsonResponse
    {
        try {
            $found = $this->requiredDocument($request, $legalDocument);

            return AdminResponse::success($this->actions->for($this->actor($request), $found)->toArray(), trans_message('legal_archive.messages.available_actions_loaded'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'document_actions', ['document_id' => $legalDocument]);
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
