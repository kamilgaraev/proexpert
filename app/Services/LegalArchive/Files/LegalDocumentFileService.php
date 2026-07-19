<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
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
        $this->scanner->assertClean($upload);

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
            return $this->database()->transaction(
                fn (): LegalArchiveDocumentVersion => $this->persistVersionWithLock(
                    $file,
                    $storedPath,
                    $upload,
                    $input,
                ),
            );
        } catch (Throwable $exception) {
            $this->fileService->delete($storedPath, $organization);

            throw $exception;
        }
    }

    private function persistVersionWithLock(
        LegalArchiveDocumentFile $file,
        string $storedPath,
        UploadedFile $upload,
        VersionInput $input,
    ): LegalArchiveDocumentVersion {
        $lockedFile = LegalArchiveDocumentFile::query()->whereKey($file->getKey())->lockForUpdate()->firstOrFail();
        $versionNumber = $input->versionNumber ?? $this->nextVersionNumber($lockedFile);

        if ($input->makeCurrent) {
            LegalArchiveDocumentVersion::query()
                ->where('document_file_id', $lockedFile->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);
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
            'is_current' => $input->makeCurrent,
            'status' => 'uploaded',
            'processing_status' => 'ready',
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

        if ($input->makeCurrent) {
            $lockedFile->forceFill(['current_version_id' => $version->id])->save();
        }

        return $version;
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

    private function database(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
