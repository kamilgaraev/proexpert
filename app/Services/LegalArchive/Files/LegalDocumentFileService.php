<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\Storage\FileService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class LegalDocumentFileService
{
    public function __construct(
        private readonly FileService $fileService,
        private readonly LegalDocumentFilePolicy $policy,
        private readonly LegalDocumentScanner $scanner,
        private readonly ?ConnectionInterface $connection = null,
        private readonly ?LegalDocumentAudit $audit = null,
    ) {}

    public function addVersion(
        LegalArchiveDocumentFile $file,
        UploadedFile $upload,
        VersionInput $input,
    ): LegalArchiveDocumentVersion {
        $this->policy->assertUploadAllowed($upload);
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
            $file = LegalArchiveDocumentFile::query()
                ->whereKey($version->document_file_id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked = LegalArchiveDocumentVersion::query()
                ->where('document_file_id', $file->id)
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();
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
        $lockedFile = LegalArchiveDocumentFile::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();
        $versionNumber = $input->versionNumber ?? $this->nextVersionNumber($lockedFile);
        $hasCurrent = $lockedFile->current_version_id !== null
            && LegalArchiveDocumentVersion::query()->whereKey($lockedFile->current_version_id)->exists();
        $makeCurrent = $input->makeCurrent || ! $hasCurrent;

        if ($makeCurrent && $hasCurrent) {
            $this->setVersionCurrent((int) $lockedFile->current_version_id, false);
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
            $this->setCurrentPointers($lockedFile, $version->id);
        }

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

    private function markFailedAndReconcileCurrent(
        LegalArchiveDocumentVersion $version,
    ): LegalArchiveDocumentVersion {
        return $this->database()->transaction(function () use ($version): LegalArchiveDocumentVersion {
            $file = LegalArchiveDocumentFile::query()
                ->whereKey($version->document_file_id)
                ->lockForUpdate()
                ->firstOrFail();
            $failed = LegalArchiveDocumentVersion::query()
                ->where('document_file_id', $file->id)
                ->whereKey($version->getKey())
                ->lockForUpdate()
                ->firstOrFail();
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
                    $this->setVersionCurrent((int) $fallback->id, true);
                }
                $this->setCurrentPointers($file, $fallback?->id);
            }

            return $failed->refresh();
        }, 3);
    }

    private function recordCleanupDebt(
        int $organizationId,
        string $storagePath,
        string $reason,
        Throwable $exception,
    ): void {
        $this->database()->table('legal_archive_file_cleanup_debts')->insert([
            'organization_id' => $organizationId,
            'storage_path' => $storagePath,
            'reason' => $reason,
            'attempts' => 1,
            'next_attempt_at' => now(),
            'last_error' => $exception::class,
            'resolved_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function setVersionCurrent(int $versionId, bool $current): void
    {
        $version = LegalArchiveDocumentVersion::query()->whereKey($versionId)->lockForUpdate()->firstOrFail();
        LegalArchiveDocumentVersion::technicalMutation(function () use ($version, $current): void {
            $version->is_current = $current;
            $version->save();
        });
    }

    private function setCurrentPointers(LegalArchiveDocumentFile $file, ?int $versionId): void
    {
        $file->forceFill(['current_version_id' => $versionId])->save();
        if ($file->role === 'primary') {
            LegalArchiveDocument::query()->whereKey($file->document_id)->lockForUpdate()->firstOrFail()
                ->forceFill(['current_primary_version_id' => $versionId])->save();
        }
    }

    private function nextVersionNumber(LegalArchiveDocumentFile $file): string
    {
        $maximum = $file->versions()
            ->lockForUpdate()
            ->pluck('version_number')
            ->reduce(
                static fn (int $carry, mixed $number): int => max($carry, ctype_digit((string) $number) ? (int) $number : 0),
                0,
            );

        return (string) ($maximum + 1);
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
