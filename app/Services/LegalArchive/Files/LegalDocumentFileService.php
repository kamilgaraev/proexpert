<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\CanonicalJson;
use App\Services\LegalArchive\Editor\LegalDocumentEditGuard;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\LegalDocumentAggregateLock;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LegalDocumentFileService
{
    private readonly LegalDocumentAggregateLock $aggregateLock;

    public function __construct(
        private readonly FileService $fileService,
        private readonly LegalDocumentFilePolicy $policy,
        private readonly LegalDocumentScanner $scanner,
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?LegalDocumentAudit $audit = null,
        ?LegalDocumentAggregateLock $aggregateLock = null,
    ) {
        $this->aggregateLock = $aggregateLock ?? new LegalDocumentAggregateLock;
    }

    public function addVersion(
        LegalArchiveDocumentFile $file,
        UploadedFile $upload,
        VersionInput $input,
        ?LegalDocumentVersionAttempt $attempt = null,
    ): LegalArchiveDocumentVersion {
        $this->policy->assertUploadAllowed($upload);
        if ($attempt !== null) {
            return $this->addFencedVersion($file, $upload, $input, $attempt);
        }
        if ($input->versionNumber !== null) {
            $this->assertVersionNumberAvailable($file, $input->versionNumber);
        }

        $organization = new Organization;
        $organization->forceFill(['id' => (int) $file->organization_id]);
        $storedPath = $this->fileService->upload(
            $upload,
            "legal-archive/files/{$file->id}/versions",
            null,
            'private',
            $organization,
            true,
            true,
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('legal_document_upload_failed');
        }

        try {
            $version = $this->database()->transaction(
                fn (): LegalArchiveDocumentVersion => $this->persistQuarantineWithLock(
                    $file,
                    $storedPath,
                    $upload,
                    $input,
                ),
                3,
            );
        } catch (Throwable $exception) {
            if (! $this->fileService->delete($storedPath, $organization)) {
                $this->recordCleanupDebt(
                    (int) $file->organization_id,
                    $storedPath,
                    'version_persistence_failed',
                    $exception,
                );
            }

            if ($exception instanceof LegalArchiveLockConflict || $exception instanceof LegalDocumentVersionNumberConflict) {
                throw $exception;
            }

            throw new LegalDocumentVersionPersistenceFailed($exception);
        }

        try {
            $this->scanner->assertClean($upload);
        } catch (Throwable $exception) {
            $failed = $this->markFailedAndReconcileCurrent($version);

            throw new LegalDocumentScanFailed($failed, $exception);
        }

        return $this->markReady($version);
    }

    private function addFencedVersion(
        LegalArchiveDocumentFile $file,
        UploadedFile $upload,
        VersionInput $input,
        LegalDocumentVersionAttempt $attempt,
    ): LegalArchiveDocumentVersion {
        $descriptor = UploadedFileDescriptor::fromUpload($upload);
        $reservation = $this->reserveOperation($file, $input, $descriptor, $attempt);
        if ($reservation->document_version_id !== null) {
            $existing = LegalArchiveDocumentVersion::query()->findOrFail((int) $reservation->document_version_id);
            if ((string) $reservation->status === 'completed') {
                $attempt->complete($existing);

                return $existing;
            }
            if ((string) $reservation->status === 'failed') {
                $existing = $this->reopenFencedFailed($existing, $attempt, (int) $reservation->id);
            }

            return $this->scanAndFinalizeFenced($existing, $upload, $attempt, (int) $reservation->id);
        }

        $organization = new Organization;
        $organization->forceFill(['id' => (int) $file->organization_id]);
        $attempt->heartbeat();
        $storedPath = $this->fileService->upload(
            $upload,
            "legal-archive/files/{$file->id}/versions",
            null,
            'private',
            $organization,
            true,
            true,
        );
        if (! is_string($storedPath) || $storedPath === '') {
            throw new RuntimeException('legal_document_upload_failed');
        }

        try {
            $attempt->heartbeat();
            $version = $this->persistFencedQuarantine(
                $file,
                $storedPath,
                $upload,
                $input,
                $attempt,
                (int) $reservation->id,
                (string) $reservation->reserved_version_number,
            );
        } catch (Throwable $exception) {
            $this->cleanupUploadedObject($file, $storedPath, 'version_fence_lost_or_persistence_failed', $exception);
            if ($exception instanceof LegalDocumentVersionLeaseLost || $exception instanceof LegalDocumentVersionNumberConflict) {
                throw $exception;
            }

            throw new LegalDocumentVersionPersistenceFailed($exception);
        }

        return $this->scanAndFinalizeFenced($version, $upload, $attempt, (int) $reservation->id);
    }

    private function scanAndFinalizeFenced(
        LegalArchiveDocumentVersion $version,
        UploadedFile $upload,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
    ): LegalArchiveDocumentVersion {
        try {
            $attempt->heartbeat();
            $this->scanner->assertClean($upload);
            $attempt->heartbeat();
        } catch (LegalDocumentVersionLeaseLost $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $failed = $this->markFencedFailed($version, $attempt, $operationId);

            throw new LegalDocumentScanFailed($failed, $exception);
        }

        return $this->markFencedReady($version, $attempt, $operationId);
    }

    private function reserveOperation(
        LegalArchiveDocumentFile $file,
        VersionInput $input,
        UploadedFileDescriptor $descriptor,
        LegalDocumentVersionAttempt $attempt,
    ): object {
        return $this->database()->transaction(function () use ($file, $input, $descriptor, $attempt): object {
            $document = $this->aggregateLock->lockDocument(
                $this->database(),
                (int) $file->organization_id,
                (int) $file->document_id,
            );
            $lockedFile = $this->aggregateLock->lockFile($this->database(), $document, (int) $file->getKey());
            $operation = $this->database()->table('legal_archive_document_version_operations')
                ->where('organization_id', $lockedFile->organization_id)
                ->where('document_file_id', $lockedFile->id)
                ->where('operation_id', $attempt->operationId)
                ->orderByDesc('operation_generation')
                ->lockForUpdate()
                ->first();
            if ($operation !== null) {
                if (! hash_equals((string) $operation->request_fingerprint, $input->semanticFingerprint())) {
                    throw new DomainException('legal_document_version_operation_conflict');
                }
                if (! $this->operationHasContent($operation, $descriptor)) {
                    if ((string) $operation->status !== 'failed') {
                        throw new DomainException('legal_document_version_operation_conflict');
                    }

                    return $this->createOperationReservation(
                        $document,
                        $lockedFile,
                        $input,
                        $descriptor,
                        $attempt,
                        ((int) $operation->operation_generation) + 1,
                        (string) $operation->reserved_version_number,
                    );
                }
                if ((string) $operation->status === 'completed') {
                    return $operation;
                }
                $attempt->assertOwned($document);
                if (! hash_equals((string) $operation->attempt_token, $attempt->attemptToken)) {
                    $attempt->assertOwned($document);
                    $this->database()->table('legal_archive_document_version_operations')
                        ->where('id', $operation->id)
                        ->update([
                            'attempt_token' => $attempt->attemptToken,
                            'attempt_count' => ((int) $operation->attempt_count) + 1,
                            'updated_at' => now(),
                        ]);
                }

                return $this->database()->table('legal_archive_document_version_operations')->find($operation->id);
            }

            return $this->createOperationReservation($document, $lockedFile, $input, $descriptor, $attempt, 1, null);
        }, 3);
    }

    private function createOperationReservation(
        LegalArchiveDocument $document,
        LegalArchiveDocumentFile $file,
        VersionInput $input,
        UploadedFileDescriptor $descriptor,
        LegalDocumentVersionAttempt $attempt,
        int $generation,
        ?string $previousVersionNumber,
    ): object {
        $versionNumber = match (true) {
            $generation === 1 && $input->versionNumber !== null => $input->versionNumber,
            $previousVersionNumber !== null && ctype_digit($previousVersionNumber) => (string) ((int) $previousVersionNumber + 1),
            $previousVersionNumber !== null => $previousVersionNumber.'.'.$generation,
            default => $this->nextVersionNumber($file),
        };
        $this->assertVersionNumberAvailable($file, $versionNumber);
        $attempt->assertOwned($document);
        $id = $this->database()->table('legal_archive_document_version_operations')->insertGetId([
            'organization_id' => $file->organization_id,
            'document_id' => $file->document_id,
            'document_file_id' => $file->id,
            'operation_id' => $attempt->operationId,
            'operation_generation' => $generation,
            'request_fingerprint' => $input->semanticFingerprint(),
            'reserved_version_number' => $versionNumber,
            'requested_version_number' => $input->versionNumber,
            'version_label' => $input->versionLabel,
            'uploaded_by_user_id' => $input->uploadedByUserId,
            'version_metadata' => $input->metadata === null ? null : CanonicalJson::encode($input->metadata),
            'file_original_name' => $descriptor->originalName,
            'file_size_bytes' => $descriptor->sizeBytes,
            'file_content_hash' => $descriptor->contentHash,
            'file_client_mime_type' => $descriptor->clientMimeType,
            'file_detected_mime_type' => $descriptor->detectedMimeType,
            'make_current' => $input->makeCurrent,
            'attempt_token' => $attempt->attemptToken,
            'attempt_count' => 1,
            'status' => 'reserved',
            'storage_path' => null,
            'document_version_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->database()->table('legal_archive_document_version_operations')->find($id);
    }

    private function persistFencedQuarantine(
        LegalArchiveDocumentFile $file,
        string $storedPath,
        UploadedFile $upload,
        VersionInput $input,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
        string $versionNumber,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use (
            $file,
            $storedPath,
            $upload,
            $input,
            $attempt,
            $operationId,
            $versionNumber,
        ): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->database(), (int) $file->organization_id, (int) $file->document_id);
            $lockedFile = $this->aggregateLock->lockFile($this->database(), $document, (int) $file->getKey());
            $operation = $this->lockOwnedOperation($document, $lockedFile, $attempt, $operationId);
            if ($operation->document_version_id !== null) {
                return $this->aggregateLock->lockVersion($this->database(), $document, (int) $operation->document_version_id);
            }
            $this->assertVersionNumberAvailable($lockedFile, $versionNumber, $operationId);
            $metadataHash = $input->metadata === null ? null : hash('sha256', json_encode($input->metadata, JSON_THROW_ON_ERROR));
            $realPath = $upload->getRealPath();
            $attempt->assertOwned($document);
            $version = LegalArchiveDocumentVersion::query()->create([
                'document_id' => $lockedFile->document_id,
                'document_file_id' => $lockedFile->id,
                'organization_id' => $lockedFile->organization_id,
                'version_number' => $versionNumber,
                'version_label' => $input->versionLabel,
                'is_current' => false,
                'status' => 'uploaded',
                'processing_status' => 'quarantine',
                'file_path' => $storedPath,
                'original_filename' => $upload->getClientOriginalName(),
                'mime_type' => $upload->getMimeType(),
                'size_bytes' => (int) ($upload->getSize() ?: 0),
                'content_hash' => is_string($realPath) ? hash_file('sha256', $realPath) : null,
                'metadata_hash' => $metadataHash,
                'uploaded_by_user_id' => $input->uploadedByUserId,
                'uploaded_at' => now(),
                'metadata' => $input->metadata,
            ]);
            $attempt->assertOwned($document);
            $this->database()->table('legal_archive_document_version_operations')->where('id', $operationId)->update([
                'status' => 'quarantine',
                'storage_path' => $storedPath,
                'document_version_id' => $version->id,
                'updated_at' => now(),
            ]);
            if ($this->audit !== null) {
                $attempt->assertOwned($document);
                $this->audit->recordForActorId('version_created', $document, $input->uploadedByUserId, [
                    'version_id' => (int) $version->id,
                    'document_file_id' => (int) $lockedFile->id,
                    'version_number' => $versionNumber,
                    'content_hash' => $version->content_hash,
                    'processing_status' => 'quarantine',
                    'source_event_id' => 'version-operation:'.$attempt->operationId,
                ]);
            }

            return $version;
        }, 3);
    }

    public function assertUploadAllowed(UploadedFile $upload): void
    {
        $this->policy->assertUploadAllowed($upload);
    }

    private function assertProcessingTransitionAllowed(
        LegalArchiveDocumentVersion $version,
        string $target,
    ): void {
        if (
            ! in_array($target, ['ready', 'failed'], true)
            || $version->processing_status !== 'quarantine'
            || in_array((string) $version->status, ['signed', 'frozen'], true)
        ) {
            throw new ImmutableDataException(LegalArchiveDocumentVersion::class, 'transition');
        }
    }

    private function markReady(LegalArchiveDocumentVersion $version): LegalArchiveDocumentVersion
    {
        return $this->database()->transaction(function () use ($version): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument(
                $this->database(),
                (int) $version->organization_id,
                (int) $version->document_id,
            );
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $locked = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->getKey());
            $this->assertProcessingTransitionAllowed($locked, 'ready');
            $this->authorizeDatabaseMutation();

            return LegalArchiveDocumentVersion::technicalMutation(function () use ($locked): LegalArchiveDocumentVersion {
                $locked->processing_status = 'ready';
                $locked->save();

                return $locked->refresh();
            });
        }, 3);
    }

    private function persistQuarantineWithLock(
        LegalArchiveDocumentFile $file,
        string $storedPath,
        UploadedFile $upload,
        VersionInput $input,
    ): LegalArchiveDocumentVersion {
        $this->authorizeDatabaseMutation();
        $lockedDocument = $this->aggregateLock->lockDocument(
            $this->database(),
            (int) $file->organization_id,
            (int) $file->document_id,
        );
        if ($input->expectedDocumentLockVersion !== null
            && (int) $lockedDocument->lock_version !== $input->expectedDocumentLockVersion) {
            throw LegalArchiveLockConflict::forDocument((int) $lockedDocument->id, (int) $lockedDocument->lock_version);
        }
        $lockedFile = $this->aggregateLock->lockFile($this->database(), $lockedDocument, (int) $file->getKey());
        $versionNumber = $input->versionNumber ?? $this->nextVersionNumber($lockedFile);
        $this->assertVersionNumberAvailable($lockedFile, $versionNumber);
        $hasCurrent = $lockedFile->current_version_id !== null
            && LegalArchiveDocumentVersion::query()->whereKey($lockedFile->current_version_id)->exists();
        $makeCurrent = $input->makeCurrent || ! $hasCurrent;
        if ($makeCurrent) {
            $this->assertCurrentVersionRotationAllowed($lockedDocument, $input->metadata['editor_session_id'] ?? null);
        }

        if ($makeCurrent && $hasCurrent) {
            $this->setVersionCurrent($lockedDocument, (int) $lockedFile->current_version_id, false);
        }

        $metadataHash = $input->metadata === null
            ? null
            : hash('sha256', json_encode($input->metadata, JSON_THROW_ON_ERROR));
        $realPath = $upload->getRealPath();
        $version = LegalArchiveDocumentVersion::query()->create([
            'document_id' => $lockedFile->document_id,
            'document_file_id' => $lockedFile->id,
            'organization_id' => $lockedFile->organization_id,
            'version_number' => $versionNumber,
            'version_label' => $input->versionLabel,
            'is_current' => $makeCurrent,
            'status' => 'uploaded',
            'processing_status' => 'quarantine',
            'file_path' => $storedPath,
            'original_filename' => $upload->getClientOriginalName(),
            'mime_type' => $upload->getMimeType(),
            'size_bytes' => (int) ($upload->getSize() ?: 0),
            'content_hash' => is_string($realPath) ? hash_file('sha256', $realPath) : null,
            'metadata_hash' => $metadataHash,
            'uploaded_by_user_id' => $input->uploadedByUserId,
            'uploaded_at' => now(),
            'metadata' => $input->metadata,
        ]);

        if ($makeCurrent) {
            $this->setCurrentPointers($lockedDocument, $lockedFile, $version->id);
        }
        $lockedDocument->forceFill(['lock_version' => ((int) $lockedDocument->lock_version) + 1])->save();

        if ($this->audit !== null) {
            $document = LegalArchiveDocument::query()->whereKey($lockedFile->document_id)->firstOrFail();
            $this->audit->recordForActorId('version_created', $document, $input->uploadedByUserId, [
                'version_id' => (int) $version->id,
                'document_file_id' => (int) $lockedFile->id,
                'version_number' => $versionNumber,
                'content_hash' => $version->content_hash,
                'processing_status' => 'quarantine',
                'source_event_id' => 'version:'.(string) $version->id,
            ]);
        }

        return $version;
    }

    public function makeCurrent(
        LegalArchiveDocumentVersion $version,
        int $expectedDocumentLockVersion,
        ?int $actorId = null,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version, $expectedDocumentLockVersion, $actorId): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument(
                $this->database(),
                (int) $version->organization_id,
                (int) $version->document_id,
            );
            if ((int) $document->lock_version !== $expectedDocumentLockVersion) {
                throw LegalArchiveLockConflict::forDocument((int) $document->id, (int) $document->lock_version);
            }
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $version = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->id);
            if ($version->processing_status !== 'ready') {
                throw new DomainException('version_not_ready');
            }
            $this->assertCurrentVersionRotationAllowed($document);
            if ($file->current_version_id !== null && (int) $file->current_version_id !== (int) $version->id) {
                $this->setVersionCurrent($document, (int) $file->current_version_id, false);
            }
            $this->setVersionCurrent($document, (int) $version->id, true);
            $this->setCurrentPointers($document, $file, (int) $version->id);
            $document->forceFill(['lock_version' => $expectedDocumentLockVersion + 1])->save();
            if ($this->audit !== null) {
                $this->audit->recordForActorId('version_made_current', $document, $actorId, [
                    'version_id' => (int) $version->id,
                    'document_file_id' => (int) $file->id,
                    'source_event_id' => 'version-current:'.(string) $version->id.':lock-'.($expectedDocumentLockVersion + 1),
                ]);
            }

            return $version->refresh();
        }, 3);
    }

    private function markFailedAndReconcileCurrent(
        LegalArchiveDocumentVersion $version,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument(
                $this->database(),
                (int) $version->organization_id,
                (int) $version->document_id,
            );
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $failed = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->getKey());
            $this->assertProcessingTransitionAllowed($failed, 'failed');
            $this->authorizeDatabaseMutation();
            $wasCurrent = (int) $file->current_version_id === (int) $failed->id;
            $fallback = $wasCurrent
                ? $file->versions()
                    ->whereKeyNot($failed->id)
                    ->where('processing_status', 'ready')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first()
                : null;

            LegalArchiveDocumentVersion::technicalMutation(function () use ($failed, $wasCurrent): void {
                $failed->processing_status = 'failed';
                if ($wasCurrent) {
                    $failed->is_current = false;
                }
                $failed->save();
            });
            if ($wasCurrent) {
                if ($fallback instanceof LegalArchiveDocumentVersion) {
                    $this->setVersionCurrent($document, (int) $fallback->id, true);
                }
                $this->setCurrentPointers($document, $file, $fallback?->id);
            }

            return $failed->refresh();
        }, 3);
    }

    private function markFencedReady(
        LegalArchiveDocumentVersion $version,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version, $attempt, $operationId): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->database(), (int) $version->organization_id, (int) $version->document_id);
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $locked = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->getKey());
            $operation = $this->lockOwnedOperation($document, $file, $attempt, $operationId);
            if ((string) $operation->status === 'completed') {
                return $locked;
            }
            $this->assertProcessingTransitionAllowed($locked, 'ready');
            $hasCurrent = $file->current_version_id !== null
                && LegalArchiveDocumentVersion::query()->whereKey($file->current_version_id)->exists();
            $makeCurrent = ! $hasCurrent || $this->operationMakesCurrent($operationId);
            if ($makeCurrent) {
                $operationInput = VersionInput::fromOperation($operation);
                $this->assertCurrentVersionRotationAllowed($document, $operationInput->metadata['editor_session_id'] ?? null);
            }
            $this->authorizeDatabaseMutation();
            if ($makeCurrent && $hasCurrent) {
                $this->setFencedVersionCurrent($document, (int) $file->current_version_id, false, $attempt);
            }
            $attempt->assertOwned($document);
            LegalArchiveDocumentVersion::technicalMutation(function () use ($locked, $makeCurrent): void {
                $locked->processing_status = 'ready';
                $locked->is_current = $makeCurrent;
                $locked->save();
            });
            if ($makeCurrent) {
                $this->setFencedCurrentPointers($document, $file, (int) $locked->id, $attempt);
            }
            $attempt->assertOwned($document);
            $this->database()->table('legal_archive_document_version_operations')->where('id', $operationId)->update([
                'status' => 'completed',
                'updated_at' => now(),
            ]);

            $attempt->complete($locked);

            return $locked->refresh();
        }, 3);
    }

    private function markFencedFailed(
        LegalArchiveDocumentVersion $version,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version, $attempt, $operationId): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->database(), (int) $version->organization_id, (int) $version->document_id);
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $locked = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->getKey());
            $this->lockOwnedOperation($document, $file, $attempt, $operationId);
            $this->assertProcessingTransitionAllowed($locked, 'failed');
            $this->authorizeDatabaseMutation();
            $attempt->assertOwned($document);
            LegalArchiveDocumentVersion::technicalMutation(function () use ($locked): void {
                $locked->processing_status = 'failed';
                $locked->is_current = false;
                $locked->save();
            });
            $attempt->assertOwned($document);
            $this->database()->table('legal_archive_document_version_operations')->where('id', $operationId)->update([
                'status' => 'failed',
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function reopenFencedFailed(
        LegalArchiveDocumentVersion $version,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version, $attempt, $operationId): LegalArchiveDocumentVersion {
            $document = $this->aggregateLock->lockDocument($this->database(), (int) $version->organization_id, (int) $version->document_id);
            $file = $this->aggregateLock->lockFile($this->database(), $document, (int) $version->document_file_id);
            $locked = $this->aggregateLock->lockVersion($this->database(), $document, (int) $version->getKey());
            $this->lockOwnedOperation($document, $file, $attempt, $operationId);
            if ((string) $locked->processing_status !== 'failed') {
                throw new LegalDocumentVersionLeaseLost;
            }
            $this->authorizeDatabaseMutation();
            $attempt->assertOwned($document);
            LegalArchiveDocumentVersion::technicalMutation(function () use ($locked): void {
                $locked->processing_status = 'quarantine';
                $locked->save();
            });
            $attempt->assertOwned($document);
            $this->database()->table('legal_archive_document_version_operations')->where('id', $operationId)->update([
                'status' => 'quarantine',
                'updated_at' => now(),
            ]);

            return $locked->refresh();
        }, 3);
    }

    private function lockOwnedOperation(
        LegalArchiveDocument $document,
        LegalArchiveDocumentFile $file,
        LegalDocumentVersionAttempt $attempt,
        int $operationId,
    ): object {
        $operation = $this->database()->table('legal_archive_document_version_operations')
            ->where('id', $operationId)
            ->where('organization_id', $document->organization_id)
            ->where('document_id', $document->id)
            ->where('document_file_id', $file->id)
            ->lockForUpdate()
            ->first();
        if ($operation === null
            || ! hash_equals((string) $operation->operation_id, $attempt->operationId)
            || ! hash_equals((string) $operation->attempt_token, $attempt->attemptToken)
        ) {
            throw new LegalDocumentVersionLeaseLost;
        }
        $attempt->assertOwned($document);

        return $operation;
    }

    private function operationMakesCurrent(int $operationId): bool
    {
        $operation = $this->database()->table('legal_archive_document_version_operations')->find($operationId);

        return $operation !== null && (bool) $operation->make_current;
    }

    public function lockVersionInputForRecovery(
        LegalArchiveDocumentFile $file,
        string $operationId,
    ): ?VersionInput {
        $operation = $this->database()->table('legal_archive_document_version_operations')
            ->where('organization_id', $file->organization_id)
            ->where('document_id', $file->document_id)
            ->where('document_file_id', $file->id)
            ->where('operation_id', $operationId)
            ->orderByDesc('operation_generation')
            ->lockForUpdate()
            ->first();

        if ($operation === null) {
            return null;
        }
        $input = VersionInput::fromOperation($operation);
        if (! hash_equals((string) $operation->request_fingerprint, $input->semanticFingerprint())) {
            throw new DomainException('legal_document_version_operation_input_corrupted');
        }

        return $input;
    }

    private function operationHasContent(object $operation, UploadedFileDescriptor $descriptor): bool
    {
        $persisted = new UploadedFileDescriptor(
            (string) $operation->file_original_name,
            (int) $operation->file_size_bytes,
            (string) $operation->file_content_hash,
            isset($operation->file_client_mime_type) ? (string) $operation->file_client_mime_type : null,
            isset($operation->file_detected_mime_type) ? (string) $operation->file_detected_mime_type : null,
        );

        return hash_equals($persisted->contentIdentity(), $descriptor->contentIdentity());
    }

    private function cleanupUploadedObject(
        LegalArchiveDocumentFile $file,
        string $storedPath,
        string $reason,
        Throwable $exception,
    ): void {
        $organization = new Organization;
        $organization->forceFill(['id' => (int) $file->organization_id]);
        if (! $this->fileService->delete($storedPath, $organization)) {
            $this->recordCleanupDebt((int) $file->organization_id, $storedPath, $reason, $exception);
        }
    }

    private function recordCleanupDebt(
        int $organizationId,
        string $storagePath,
        string $reason,
        Throwable $exception,
    ): void {
        $now = now();
        $debtKey = LegalCleanupDebtKey::for($organizationId, $storagePath, null);
        $row = [
            'organization_id' => $organizationId,
            'storage_path' => $storagePath,
            'reason' => $reason,
            'attempts' => 1,
            'next_attempt_at' => $now,
            'last_error' => $exception::class,
            'resolved_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $uniqueBy = ['organization_id', 'storage_path'];
        if ($this->database()->getSchemaBuilder()->hasColumn('legal_archive_file_cleanup_debts', 'debt_key')) {
            $row['debt_key'] = $debtKey;
            $uniqueBy = ['organization_id', 'debt_key'];
        }
        $this->database()->table('legal_archive_file_cleanup_debts')->upsert([$row], $uniqueBy, [
            'reason',
            'next_attempt_at',
            'last_error',
            'resolved_at',
            'updated_at',
        ]);
    }

    private function setVersionCurrent(LegalArchiveDocument $document, int $versionId, bool $current): void
    {
        $version = $this->aggregateLock->lockVersion($this->database(), $document, $versionId);
        LegalArchiveDocumentVersion::technicalMutation(function () use ($version, $current): void {
            $version->is_current = $current;
            $version->save();
        });
    }

    private function setFencedVersionCurrent(
        LegalArchiveDocument $document,
        int $versionId,
        bool $current,
        LegalDocumentVersionAttempt $attempt,
    ): void {
        $version = $this->aggregateLock->lockVersion($this->database(), $document, $versionId);
        $attempt->assertOwned($document);
        LegalArchiveDocumentVersion::technicalMutation(function () use ($version, $current): void {
            $version->is_current = $current;
            $version->save();
        });
    }

    private function setFencedCurrentPointers(
        LegalArchiveDocument $document,
        LegalArchiveDocumentFile $file,
        int $versionId,
        LegalDocumentVersionAttempt $attempt,
    ): void {
        $attempt->assertOwned($document);
        $file->forceFill(['current_version_id' => $versionId])->save();
        if ($file->role === 'primary') {
            $attempt->assertOwned($document);
            $document->forceFill(['current_primary_version_id' => $versionId])->save();
        }
    }

    private function setCurrentPointers(
        LegalArchiveDocument $document,
        LegalArchiveDocumentFile $file,
        ?int $versionId,
    ): void {
        $file->forceFill(['current_version_id' => $versionId])->save();
        if ($file->role === 'primary') {
            $document->forceFill(['current_primary_version_id' => $versionId])->save();
        }
    }

    private function nextVersionNumber(LegalArchiveDocumentFile $file): string
    {
        $versionNumbers = $file->versions()
            ->lockForUpdate()
            ->pluck('version_number');
        $reservedVersionNumbers = $this->database()->table('legal_archive_document_version_operations')
            ->where('document_file_id', $file->id)
            ->lockForUpdate()
            ->pluck('reserved_version_number');
        $maximum = $versionNumbers
            ->merge($reservedVersionNumbers)
            ->reduce(
                function (?string $carry, mixed $number): ?string {
                    $value = (string) $number;
                    if (! ctype_digit($value)) {
                        return $carry;
                    }
                    $normalized = ltrim($value, '0');
                    $normalized = $normalized === '' ? '0' : $normalized;

                    if ($carry === null || strlen($normalized) > strlen($carry) || (strlen($normalized) === strlen($carry) && strcmp($normalized, $carry) > 0)) {
                        return $normalized;
                    }

                    return $carry;
                },
                null,
            );

        return $this->incrementNumericVersionNumber($maximum ?? '0');
    }

    private function incrementNumericVersionNumber(string $number): string
    {
        $digits = str_split($number);
        $carry = 1;
        for ($index = count($digits) - 1; $index >= 0 && $carry === 1; $index--) {
            $digit = (int) $digits[$index] + 1;
            $digits[$index] = (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }
        if ($carry === 1) {
            array_unshift($digits, '1');
        }

        return implode('', $digits);
    }

    private function assertVersionNumberAvailable(
        LegalArchiveDocumentFile $file,
        string $versionNumber,
        ?int $ownOperationId = null,
    ): void
    {
        if ($file->versions()->where('version_number', $versionNumber)->exists()) {
            throw new LegalDocumentVersionNumberConflict;
        }

        $reservation = $this->database()->table('legal_archive_document_version_operations')
            ->where('document_file_id', $file->id)
            ->where('reserved_version_number', $versionNumber)
            ->when(
                $ownOperationId !== null,
                static fn ($query) => $query->where('id', '!=', $ownOperationId),
            )
            ->exists();
        if ($reservation) {
            throw new LegalDocumentVersionNumberConflict;
        }
    }

    private function assertCurrentVersionRotationAllowed(LegalArchiveDocument $document, ?string $editorSessionId = null): void
    {
        (new LegalDocumentEditGuard($this->database()))->assertVersionMutationAllowed($document, $editorSessionId);
    }

    private function authorizeDatabaseMutation(): void
    {
        if ($this->database()->getDriverName() === 'pgsql') {
            $this->database()->statement("SET LOCAL most.legal_archive_version_mutation = 'service'");
        }
    }

    private function database(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
