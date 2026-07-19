<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
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
            $this->fileService->delete($storedPath, $organization);

            throw new LegalDocumentVersionPersistenceFailed($exception);
        }

        try {
            $this->scanner->assertClean($upload);
        } catch (Throwable $exception) {
            $this->transitionProcessingStatus($version, 'failed');
            $this->removeFailedCurrent($version);

            throw new LegalDocumentScanFailed($exception);
        }

        return $this->transitionProcessingStatus($version, 'ready');
    }

    public function transitionProcessingStatus(
        LegalArchiveDocumentVersion $version,
        string $target,
    ): LegalArchiveDocumentVersion {
        if (
            ! in_array($target, ['ready', 'failed'], true)
            || $version->processing_status !== 'quarantine'
            || in_array((string) $version->status, ['signed', 'frozen'], true)
        ) {
            throw new ImmutableDataException(LegalArchiveDocumentVersion::class, 'transition');
        }

        return $this->database()->transaction(function () use ($version, $target): LegalArchiveDocumentVersion {
            $this->authorizeDatabaseMutation();
            $locked = LegalArchiveDocumentVersion::query()->whereKey($version->getKey())->lockForUpdate()->firstOrFail();

            return LegalArchiveDocumentVersion::technicalMutation(function () use ($locked, $target): LegalArchiveDocumentVersion {
                $locked->processing_status = $target;
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

        return $version;
    }

    private function removeFailedCurrent(LegalArchiveDocumentVersion $failed): void
    {
        $this->database()->transaction(function () use ($failed): void {
            $this->authorizeDatabaseMutation();
            $file = LegalArchiveDocumentFile::query()->whereKey($failed->document_file_id)->lockForUpdate()->firstOrFail();
            if ((int) $file->current_version_id !== (int) $failed->id) {
                return;
            }

            $fallback = $file->versions()
                ->whereKeyNot($failed->id)
                ->where('processing_status', 'ready')
                ->orderByDesc('id')
                ->first();
            $this->setVersionCurrent((int) $failed->id, false);
            if ($fallback instanceof LegalArchiveDocumentVersion) {
                $this->setVersionCurrent((int) $fallback->id, true);
            }
            $this->setCurrentPointers($file, $fallback?->id);
        }, 3);
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
