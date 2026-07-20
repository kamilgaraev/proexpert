<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveDocumentIndexRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\RecoverLegalDocumentManagementRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveDocumentRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentVersionResource;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\LegalArchiveDictionary;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use App\Services\LegalArchive\LegalDocumentCreateFailed;
use App\Services\LegalArchive\LegalDocumentCreateFailureReporter;
use App\Services\LegalArchive\LegalDocumentCreateInProgress;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

use function trans_message;

final class LegalArchiveController extends Controller
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registryService,
        private readonly LegalDocumentAuthorizer $access,
        private readonly LegalDocumentAccessService $accessManagement,
        private readonly LegalDocumentCreateFailureReporter $createFailureReporter,
    ) {}

    public function dictionaries(): JsonResponse
    {
        try {
            return AdminResponse::success([
                'types' => LegalArchiveDictionary::options('types'),
                'statuses' => LegalArchiveDictionary::options('statuses'),
                'directions' => LegalArchiveDictionary::options('directions'),
                'legal_significance_statuses' => LegalArchiveDictionary::options('legal_significance_statuses'),
                'link_types' => LegalArchiveDictionary::options('link_types'),
                'version_statuses' => LegalArchiveDictionary::options('version_statuses'),
            ], trans_message('legal_archive.messages.dictionaries_loaded'));
        } catch (Throwable $e) {
            Log::error('legal_archive.dictionaries_error', [
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.documents_load_error'), 500);
        }
    }

    public function index(LegalArchiveDocumentIndexRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.documents_load_error'), 403);
            }
            $documents = $this->registryService->paginate($actor, $this->organizationId($request), $filters);

            return AdminResponse::paginated(
                LegalArchiveDocumentResource::collection($documents->getCollection()),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
                trans_message('legal_archive.messages.documents_loaded'),
                200,
                $this->registryService->summary($actor, $this->organizationId($request), $filters)
            );
        } catch (Throwable $e) {
            Log::error('legal_archive.documents.index_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.documents_load_error'), 500);
        }
    }

    public function show(Request $request, string $document): JsonResponse
    {
        try {
            $found = $this->registryService->findForAuthorization((int) $document);

            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $this->access->authorize($actor, $found, 'view');

            return AdminResponse::success(
                new LegalArchiveDocumentResource($found),
                trans_message('legal_archive.messages.document_loaded')
            );
        } catch (Throwable $e) {
            if ($e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            Log::error('legal_archive.documents.show_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'document_id' => $document,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.document_load_error'), 500);
        }
    }

    public function store(StoreLegalArchiveDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->registryService->create(
                $this->organizationId($request),
                $this->userId($request),
                $request->validated(),
                $this->uploadedFile($request)
            );

            return AdminResponse::success(
                new LegalArchiveDocumentResource($document),
                trans_message('legal_archive.messages.document_created'),
                201
            );
        } catch (Throwable $e) {
            if ($e instanceof LegalDocumentCreateInProgress) {
                return $this->createInProgressResponse($e);
            }
            if ($e instanceof LegalDocumentCreateFailed) {
                $this->reportCreateFailure($request, $e, $e->document);

                return AdminResponse::success(
                    new LegalArchiveDocumentResource($e->document->refresh()),
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_create_failed',
                        'operation_id' => $e->operationId(),
                        'retry_action' => $e->retryAction(),
                        'retry_document_id' => (int) $e->document->id,
                    ],
                );
            }
            if ($e instanceof LegalDocumentScanFailed) {
                $document = $this->registryService->findForOrganization(
                    $this->organizationId($request),
                    (int) $e->version->document_id,
                );
                if ($document instanceof LegalArchiveDocument) {
                    $this->reportCreateFailure($request, $e, $document);
                }

                return AdminResponse::success(
                    $document instanceof LegalArchiveDocument ? new LegalArchiveDocumentResource($document) : null,
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_create_failed',
                        'operation_id' => $document?->create_operation_id,
                        'retry_action' => 'retry_upload',
                        'retry_document_id' => (int) $e->version->document_id,
                    ],
                );
            }

            if ($e instanceof LegalDocumentFileRejected) {
                return AdminResponse::error(
                    trans_message('legal_archive.messages.validation_error'),
                    422,
                    ['file' => [$e->getMessage()]],
                );
            }

            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }

            Log::error('legal_archive.documents.store_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.document_create_error'), 500);
        }
    }

    public function recoveryIndex(Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $documents = $this->registryService->paginateRecoveries(
                $actor,
                $this->organizationId($request),
                (int) $request->integer('per_page', 20),
            );

            return AdminResponse::paginated(
                LegalArchiveDocumentResource::collection($documents->getCollection()),
                [
                    'current_page' => $documents->currentPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                    'last_page' => $documents->lastPage(),
                ],
                trans_message('legal_archive.messages.create_recoveries_loaded'),
            );
        } catch (Throwable $e) {
            Log::error('legal_archive.documents.recovery_index_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'error_class' => $e::class,
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.documents_load_error'), 500);
        }
    }

    public function recoverCreate(RecoverLegalArchiveDocumentRequest $request, string $operation): JsonResponse
    {
        try {
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $document = $this->registryService->recoverCreate(
                $actor,
                $this->organizationId($request),
                $operation,
                $this->uploadedFile($request),
            );

            return AdminResponse::success(
                new LegalArchiveDocumentResource($document),
                trans_message('legal_archive.messages.create_recovery_completed'),
            );
        } catch (Throwable $e) {
            if ($e instanceof LegalDocumentCreateInProgress) {
                return $this->createInProgressResponse($e);
            }
            if ($e instanceof ModelNotFoundException || $e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }
            if ($e instanceof LegalDocumentFileRejected) {
                return AdminResponse::error(
                    trans_message('legal_archive.messages.validation_error'),
                    422,
                    ['file' => [$e->getMessage()]],
                );
            }
            if ($e instanceof LegalDocumentCreateFailed || $e instanceof LegalDocumentScanFailed) {
                $document = $e instanceof LegalDocumentCreateFailed
                    ? $e->document->refresh()
                    : $this->registryService->findForOrganization(
                        $this->organizationId($request),
                        (int) $e->version->document_id,
                    );
                if ($document instanceof LegalArchiveDocument) {
                    $this->reportCreateFailure($request, $e, $document);
                }

                return AdminResponse::success(
                    $document instanceof LegalArchiveDocument ? new LegalArchiveDocumentResource($document) : null,
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_create_failed',
                        'operation_id' => $document?->create_operation_id,
                        'retry_action' => $document?->source_create_retry_action,
                        'retry_document_id' => $document?->id,
                    ],
                );
            }
            Log::error('legal_archive.documents.recovery_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'operation_id' => $operation,
                'error_class' => $e::class,
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.create_recovery_error'), 500);
        }
    }

    public function update(UpdateLegalArchiveDocumentRequest $request, string $document): JsonResponse
    {
        try {
            $found = $this->registryService->findForOrganization($this->organizationId($request), (int) $document);

            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $this->access->authorizePermission($actor, $found, 'legal_archive.update');

            $updated = $this->registryService->update(
                $found,
                $this->organizationId($request),
                $this->userId($request),
                $request->validated()
            );

            return AdminResponse::success(
                new LegalArchiveDocumentResource($updated),
                trans_message('legal_archive.messages.document_updated')
            );
        } catch (Throwable $e) {
            if ($e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }

            Log::error('legal_archive.documents.update_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'document_id' => $document,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.document_update_error'), 500);
        }
    }

    public function recoverManagement(
        RecoverLegalDocumentManagementRequest $request,
        string $document,
    ): JsonResponse {
        try {
            $found = $this->registryService->findForOrganization(
                $this->organizationId($request),
                (int) $document,
            );
            $actor = $request->user();
            if ($found === null || ! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $grant = $this->accessManagement->recoverManagementAsSecurityAdministrator(
                $found,
                $actor,
                (int) $request->validated('successor_user_id'),
            );

            return AdminResponse::success(
                new LegalArchiveDocumentResource($found->refresh()),
                trans_message('legal_archive.messages.management_recovered'),
                200,
                [
                    'grant_id' => (int) $grant->id,
                    'successor_user_id' => (int) $grant->subject_user_id,
                ],
            );
        } catch (Throwable $e) {
            if ($e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            if ($e instanceof DomainException) {
                $message = $e->getMessage() === 'legal_document_access_recovery_not_required'
                    ? 'legal_archive.messages.management_recovery_not_required'
                    : 'legal_archive.messages.management_successor_ineligible';

                return AdminResponse::error(trans_message($message), 409);
            }
            Log::error('legal_archive.documents.management_recovery_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'document_id' => $document,
                'error_class' => $e::class,
            ]);

            return AdminResponse::error(
                trans_message('legal_archive.messages.management_recovery_error'),
                500,
            );
        }
    }

    public function storeVersion(StoreLegalArchiveVersionRequest $request, string $document): JsonResponse
    {
        try {
            $found = $this->registryService->findForOrganization($this->organizationId($request), (int) $document);

            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $this->access->authorizePermission($actor, $found, 'legal_archive.versions.create');
            $this->access->authorizePermission($actor, $found, 'legal_archive.files.upload');

            $file = $this->uploadedFile($request);

            if (! $file instanceof UploadedFile) {
                throw ValidationException::withMessages([
                    'file' => [trans_message('legal_archive.messages.file_required')],
                ]);
            }

            $version = $this->registryService->addVersion(
                $found,
                $this->organizationId($request),
                $this->userId($request),
                $request->validated(),
                $file,
                (bool) ($request->validated('make_current') ?? true)
            );

            return AdminResponse::success(
                new LegalArchiveDocumentVersionResource($version),
                trans_message('legal_archive.messages.version_created'),
                201
            );
        } catch (Throwable $e) {
            if ($e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            if ($e instanceof LegalDocumentScanFailed) {
                return AdminResponse::success(
                    new LegalArchiveDocumentVersionResource($e->version),
                    trans_message('legal_archive.messages.version_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'version_created',
                        'retry_action' => 'retry_upload',
                        'retry_document_id' => (int) $e->version->document_id,
                    ],
                );
            }

            if ($e instanceof LegalDocumentFileRejected) {
                return AdminResponse::error(
                    trans_message('legal_archive.messages.validation_error'),
                    422,
                    ['file' => [$e->getMessage()]],
                );
            }

            if ($e instanceof ValidationException) {
                return $this->validationError($e);
            }

            Log::error('legal_archive.documents.version_store_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'document_id' => $document,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.version_create_error'), 500);
        }
    }

    public function currentVersion(Request $request, string $document): JsonResponse
    {
        try {
            $found = $this->registryService->findForAuthorization((int) $document);

            if ($found === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }

            $actor = $request->user();
            if (! $actor instanceof User) {
                return AdminResponse::error(trans_message('legal_archive.messages.current_version_not_found'), 404);
            }

            $version = $this->registryService->currentVersionWithUrl($found, $actor);

            if ($version === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.current_version_not_found'), 404);
            }

            return AdminResponse::success(
                new LegalArchiveDocumentVersionResource($version),
                trans_message('legal_archive.messages.current_version_loaded')
            );
        } catch (Throwable $e) {
            if ($e instanceof AuthorizationException) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            Log::error('legal_archive.documents.current_version_error', [
                'user_id' => $request->user()?->id,
                'organization_id' => $this->organizationId($request),
                'document_id' => $document,
                'message' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('legal_archive.messages.current_version_load_error'), 500);
        }
    }

    private function validationError(ValidationException $exception): JsonResponse
    {
        return AdminResponse::error(
            trans_message('legal_archive.messages.validation_error'),
            422,
            $exception->errors()
        );
    }

    private function createInProgressResponse(LegalDocumentCreateInProgress $exception): JsonResponse
    {
        $document = $exception->document->refresh();
        $leaseExpiresAt = $document->source_create_lease_expires_at;
        $retryAfter = $leaseExpiresAt === null ? 1 : max(1, now()->diffInSeconds($leaseExpiresAt, false));

        return AdminResponse::success(
            new LegalArchiveDocumentResource($document),
            trans_message('legal_archive.messages.source_create_in_progress'),
            202,
            [
                'processing_status' => 'pending',
                'operation_result' => 'in_progress',
                'operation_id' => $document->create_operation_id,
                'lease_expires_at' => $leaseExpiresAt?->toISOString(),
                'retry_after' => $retryAfter,
            ],
        );
    }

    private function reportCreateFailure(
        Request $request,
        Throwable $failure,
        LegalArchiveDocument $document,
    ): void {
        $original = $failure instanceof LegalDocumentCreateFailed
            ? ($failure->getPrevious() ?? $failure)
            : $failure;
        $operationId = is_string($document->create_operation_id) && $document->create_operation_id !== ''
            ? $document->create_operation_id
            : 'document-'.(string) $document->id;
        $this->createFailureReporter->report(
            failure: $original,
            organizationId: (int) $document->organization_id,
            actorId: $this->userId($request),
            documentId: (int) $document->id,
            operationId: $operationId,
        );
    }

    private function uploadedFile(Request $request): ?UploadedFile
    {
        $file = $request->file('file');

        return $file instanceof UploadedFile ? $file : null;
    }

    private function userId(Request $request): ?int
    {
        $user = $request->user();

        return $user instanceof User ? (int) $user->id : null;
    }

    private function organizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id');

        if ($organizationId !== null) {
            return (int) $organizationId;
        }

        $user = $request->user();

        return $user instanceof User ? (int) $user->current_organization_id : 0;
    }
}
