<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveDocumentIndexRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveDocumentRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\UpdateLegalArchiveDocumentRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentResource;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentVersionResource;
use App\Http\Responses\AdminResponse;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\LegalArchiveDictionary;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use Illuminate\Auth\Access\AuthorizationException;
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
            if ($e instanceof LegalDocumentScanFailed) {
                $document = $this->registryService->findForOrganization(
                    $this->organizationId($request),
                    (int) $e->version->document_id,
                );

                return AdminResponse::success(
                    $document instanceof LegalArchiveDocument ? new LegalArchiveDocumentResource($document) : null,
                    trans_message('legal_archive.messages.document_file_processing_failed'),
                    202,
                    [
                        'processing_status' => 'failed',
                        'operation_result' => 'document_created',
                        'retry_action' => 'add_version',
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
            $this->access->authorize($actor, $found, 'view');

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
            $this->access->authorize($actor, $found, 'view');

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
                        'retry_action' => 'add_version',
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
