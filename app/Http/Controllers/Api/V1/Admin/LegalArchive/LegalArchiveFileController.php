<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Http\Requests\Api\V1\Admin\LegalArchive\LegalArchiveLockRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveFileRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveFileVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StoreLegalArchiveVersionRequest;
use App\Http\Requests\Api\V1\Admin\LegalArchive\StartLegalArchiveBlankEditorRequest;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveDocumentVersionResource;
use App\Http\Resources\Api\V1\Admin\LegalArchive\LegalArchiveFileResource;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Editor\LegalDocumentEditorSessionService;
use App\Services\LegalArchive\Editor\LegalDocumentBlankDraftService;
use App\Services\LegalArchive\Files\LegalDocumentDownloadService;
use App\Services\LegalArchive\Files\LegalDocumentFileRejected;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\LegalArchive\LegalArchiveRegistryService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

final class LegalArchiveFileController extends LegalArchiveApiController
{
    public function __construct(
        private readonly LegalArchiveRegistryService $registry,
        private readonly LegalDocumentFileService $files,
        private readonly LegalDocumentDownloadService $downloads,
        private readonly LegalDocumentEditorSessionService $editor,
        private readonly LegalDocumentBlankDraftService $blankDrafts,
        private readonly LegalDocumentAuthorizer $access,
    ) {}

    public function storeFile(StoreLegalArchiveFileRequest $request, string $legalDocument): JsonResponse
    {
        $created = null;
        try {
            $owner = $this->document($request, $legalDocument);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $owner, 'legal_archive.files.upload');
            $this->access->authorizePermission($actor, $owner, 'legal_archive.versions.create');
            $created = LegalArchiveDocumentFile::query()->create([
                'document_id' => (int) $owner->id,
                'organization_id' => (int) $owner->organization_id,
                'role' => (string) $request->validated('role'),
                'title' => (string) $request->validated('title'),
                'sort_order' => (int) $owner->files()->max('sort_order') + 1,
                'is_required' => false,
            ]);
            $version = $this->files->addVersion($created, $request->file('file'), $this->versionInput($request));

            return $this->etag(AdminResponse::success(new LegalArchiveFileResource($created->load('currentVersion', 'versions')), trans_message('legal_archive.messages.file_created'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
                'version_id' => (int) $version->id,
            ]), $owner->fresh());
        } catch (Throwable $error) {
            if ($error instanceof LegalDocumentScanFailed) {
                return $this->scanFailure($error);
            }
            if ($error instanceof LegalDocumentFileRejected) {
                return $this->fileRejected();
            }
            if ($created instanceof LegalArchiveDocumentFile && $created->versions()->doesntExist()) {
                $created->delete();
            }

            return $this->failure($error, $request, 'file_store', ['document_id' => $legalDocument]);
        }
    }

    public function storeVersion(StoreLegalArchiveFileVersionRequest $request, string $legalDocumentFile): JsonResponse
    {
        try {
            $logicalFile = $this->file($request, $legalDocumentFile);
            $owner = $logicalFile->document()->firstOrFail();
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $owner, 'legal_archive.files.upload');
            $this->access->authorizePermission($actor, $owner, 'legal_archive.versions.create');
            $version = $this->files->addVersion($logicalFile, $request->file('file'), $this->versionInput($request));

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentVersionResource($version), trans_message('legal_archive.messages.version_created'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
            ]), $owner->fresh());
        } catch (Throwable $error) {
            if ($error instanceof LegalDocumentScanFailed) {
                return $this->scanFailure($error);
            }
            if ($error instanceof LegalDocumentFileRejected) {
                return $this->fileRejected();
            }

            return $this->failure($error, $request, 'version_store', ['file_id' => $legalDocumentFile]);
        }
    }

    public function storePrimaryVersion(StoreLegalArchiveVersionRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $owner = $this->document($request, $legalDocument);
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $owner, 'legal_archive.files.upload');
            $this->access->authorizePermission($actor, $owner, 'legal_archive.versions.create');
            $version = $this->registry->addVersion($owner, $this->organizationId($request), (int) $actor->id, $request->validated(), $request->file('file'));

            $response = AdminResponse::success(new LegalArchiveDocumentVersionResource($version), trans_message('legal_archive.messages.version_created'), 201, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
                'deprecated_alias' => true,
            ])->withHeaders(['Deprecation' => 'true', 'Sunset' => 'Wed, 31 Dec 2026 23:59:59 GMT']);

            return $this->etag($response, $owner->fresh());
        } catch (Throwable $error) {
            if ($error instanceof LegalDocumentScanFailed) {
                return $this->scanFailure($error);
            }
            if ($error instanceof LegalDocumentFileRejected) {
                return $this->fileRejected();
            }

            return $this->failure($error, $request, 'primary_version_store', ['document_id' => $legalDocument]);
        }
    }

    private function scanFailure(LegalDocumentScanFailed $error): JsonResponse
    {
        $document = $error->version->document()->firstOrFail();
        $failureCode = $error->failureCode();
        $cause = $error->getPrevious();
        $messageKey = match ($failureCode) {
            'malware_detected' => 'legal_archive.messages.version_file_malware_detected',
            'scanner_unavailable' => 'legal_archive.messages.version_file_scan_unavailable',
            default => 'legal_archive.messages.version_file_processing_failed',
        };

        Log::warning('legal_archive.file_scan_failed', [
            'organization_id' => (int) $error->version->organization_id,
            'document_id' => (int) $error->version->document_id,
            'document_version_id' => (int) $error->version->id,
            'uploaded_by_user_id' => $error->version->uploaded_by_user_id === null
                ? null
                : (int) $error->version->uploaded_by_user_id,
            'failure_code' => $failureCode,
            'error_class' => $cause instanceof Throwable ? $cause::class : null,
        ]);

        return $this->etag(AdminResponse::success(
            new LegalArchiveDocumentVersionResource($error->version),
            trans_message($messageKey),
            202,
            [
                'processing_status' => 'failed',
                'processing_failure_code' => $failureCode,
                'retry_action' => 'retry_upload',
                'retry_document_id' => (int) $error->version->document_id,
            ],
        ), $document->fresh());
    }

    private function fileRejected(): JsonResponse
    {
        return AdminResponse::error(
            trans_message('legal_archive.messages.validation_error'),
            422,
            ['file' => [trans_message('legal_archive.messages.file_rejected')]],
        );
    }

    public function preview(Request $request, string $documentVersion): JsonResponse
    {
        try {
            return $this->fileUrl($request, $documentVersion, 'preview');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'file_preview', ['version_id' => $documentVersion]);
        }
    }

    public function download(Request $request, string $documentVersion): JsonResponse
    {
        try {
            return $this->fileUrl($request, $documentVersion, 'download');
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'file_download', ['version_id' => $documentVersion]);
        }
    }

    public function contractFileUrl(Request $request, int $project, int $contract, string $legalDocument, string $documentVersion, string $purpose): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $linkedContract = Contract::query()->whereKey($contract)->where('project_id', $project)
                ->where('organization_id', $organizationId)->first();
            $document = LegalArchiveDocument::query()->whereKey((int) $legalDocument)
                ->where('organization_id', $organizationId)->where('primary_project_id', $project)->first();
            $found = $this->version($request, $documentVersion);

            if ($linkedContract === null
                || $document === null
                || (int) $linkedContract->legal_archive_document_id !== (int) $legalDocument
                || (int) $found->document_id !== (int) $legalDocument) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }

            $url = $this->downloads->temporaryUrlForContract($found, $this->actor($request), $linkedContract, $purpose);

            return AdminResponse::success([
                'url' => $url,
                'expires_in_seconds' => max(60, (int) config('file-uploads.legal_archive.temporary_url_minutes', 5) * 60),
                'version' => new LegalArchiveDocumentVersionResource($found),
            ], trans_message('legal_archive.messages.file_url_created'))->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
                'Referrer-Policy' => 'no-referrer',
            ]);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'contract_file_url', [
                'project_id' => $project,
                'contract_id' => $contract,
                'document_id' => $legalDocument,
                'version_id' => $documentVersion,
                'purpose' => $purpose,
            ]);
        }
    }

    public function makeCurrent(LegalArchiveLockRequest $request, string $documentVersion): JsonResponse
    {
        try {
            $found = $this->version($request, $documentVersion);
            $owner = $found->document()->firstOrFail();
            $actor = $this->actor($request);
            $this->access->authorizePermission($actor, $owner, 'legal_archive.versions.manage');
            $updated = $this->files->makeCurrent($found, (int) $request->validated('lock_version'), (int) $actor->id);

            return $this->etag(AdminResponse::success(new LegalArchiveDocumentVersionResource($updated), trans_message('legal_archive.messages.current_version_changed'), 200, [
                'document_lock_version' => (int) $owner->fresh()->lock_version,
            ]), $owner->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'version_make_current', ['version_id' => $documentVersion]);
        }
    }

    public function compare(Request $request, string $documentVersion, string $otherDocumentVersion): JsonResponse
    {
        try {
            $left = $this->version($request, $documentVersion);
            $right = $this->version($request, $otherDocumentVersion);
            if ((int) $left->document_id !== (int) $right->document_id) {
                throw new DomainException('versions_not_comparable');
            }
            $this->access->authorize($this->actor($request), $left->document()->firstOrFail(), 'view');

            return AdminResponse::success([
                'left' => new LegalArchiveDocumentVersionResource($left),
                'right' => new LegalArchiveDocumentVersionResource($right),
                'same_content' => hash_equals((string) $left->content_hash, (string) $right->content_hash),
                'size_difference_bytes' => (int) $right->size_bytes - (int) $left->size_bytes,
            ], trans_message('legal_archive.messages.versions_compared'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'version_compare', ['version_id' => $documentVersion, 'other_version_id' => $otherDocumentVersion]);
        }
    }

    public function editorSession(Request $request, string $documentVersion): JsonResponse
    {
        try {
            $found = $this->version($request, $documentVersion);
            $mode = (string) $request->input('mode', 'edit');
            if (! in_array($mode, ['view', 'review', 'edit'], true)) {
                throw new DomainException('legal_document_editor_mode_invalid');
            }

            return AdminResponse::success($this->editor->open($found, $this->actor($request), $mode, $request->boolean('upgrade_mode')), trans_message('legal_archive.messages.editor_opened'));
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'editor_session', ['version_id' => $documentVersion]);
        }
    }

    public function startBlankEditorSession(StartLegalArchiveBlankEditorRequest $request, string $legalDocument): JsonResponse
    {
        try {
            $document = $this->document($request, $legalDocument);
            $result = $this->blankDrafts->start(
                $document,
                $this->actor($request),
                (string) $request->validated('title'),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success([
                'version' => new LegalArchiveDocumentVersionResource($result['version']),
                'editor_session' => $result['session'],
            ], trans_message('legal_archive.messages.editor_opened'), 201), $document->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'editor_blank_session', ['document_id' => $legalDocument]);
        }
    }

    public function startContractBlankEditorSession(StartLegalArchiveBlankEditorRequest $request, int $project, int $contract, string $legalDocument): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $linkedContract = Contract::query()->whereKey($contract)->where('project_id', $project)
                ->where('organization_id', $organizationId)->first();
            $document = LegalArchiveDocument::query()->whereKey((int) $legalDocument)
                ->where('organization_id', $organizationId)->where('primary_project_id', $project)->first();
            if ($linkedContract === null || ! $document instanceof LegalArchiveDocument
                || (int) $linkedContract->legal_archive_document_id !== (int) $document->id) {
                return AdminResponse::error(trans_message('legal_archive.messages.document_not_found'), 404);
            }
            $result = $this->blankDrafts->start(
                $document,
                $this->actor($request),
                (string) $request->validated('title'),
                (int) $request->validated('lock_version'),
            );

            return $this->etag(AdminResponse::success([
                'version' => new LegalArchiveDocumentVersionResource($result['version']),
                'editor_session' => $result['session'],
            ], trans_message('legal_archive.messages.editor_opened'), 201), $document->fresh());
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'contract_editor_blank_session', [
                'project_id' => $project, 'contract_id' => $contract, 'document_id' => $legalDocument,
            ]);
        }
    }

    public function currentPrimaryVersion(Request $request, string $legalDocument): JsonResponse
    {
        try {
            $version = $this->registry->currentVersionWithUrl($this->document($request, $legalDocument), $this->actor($request));
            if ($version === null) {
                return AdminResponse::error(trans_message('legal_archive.messages.current_version_not_found'), 404);
            }

            return AdminResponse::success(new LegalArchiveDocumentVersionResource($version), trans_message('legal_archive.messages.current_version_loaded'), 200, [
                'deprecated_alias' => true,
            ])->withHeaders(['Deprecation' => 'true', 'Sunset' => 'Wed, 31 Dec 2026 23:59:59 GMT']);
        } catch (Throwable $error) {
            return $this->failure($error, $request, 'current_primary_version', ['document_id' => $legalDocument]);
        }
    }

    private function fileUrl(Request $request, string $version, string $purpose): JsonResponse
    {
        $found = $this->version($request, $version);
        $url = $this->downloads->temporaryUrl($found, $this->actor($request), $purpose);

        return AdminResponse::success([
            'url' => $url,
            'expires_in_seconds' => max(60, (int) config('file-uploads.legal_archive.temporary_url_minutes', 5) * 60),
            'version' => new LegalArchiveDocumentVersionResource($found),
        ], trans_message('legal_archive.messages.file_url_created'))->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'self'",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function versionInput(Request $request): VersionInput
    {
        return new VersionInput(
            versionNumber: $request->input('version_number'),
            versionLabel: $request->input('version_label'),
            uploadedByUserId: (int) $this->actor($request)->id,
            metadata: $request->input('metadata'),
            makeCurrent: true,
            expectedDocumentLockVersion: (int) $request->input('lock_version'),
        );
    }

    private function document(Request $request, string $id): \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument
    {
        $document = $this->registry->findForOrganization($this->organizationId($request), (int) $id);
        if ($document === null) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $document;
    }

    private function file(Request $request, string $id): LegalArchiveDocumentFile
    {
        $file = LegalArchiveDocumentFile::query()->whereKey((int) $id)->where('organization_id', $this->organizationId($request))->first();
        if (! $file instanceof LegalArchiveDocumentFile) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $file;
    }

    private function version(Request $request, string $id): LegalArchiveDocumentVersion
    {
        $version = LegalArchiveDocumentVersion::query()->whereKey((int) $id)->where('organization_id', $this->organizationId($request))->first();
        if (! $version instanceof LegalArchiveDocumentVersion) {
            throw new \Illuminate\Auth\Access\AuthorizationException;
        }

        return $version;
    }
}
