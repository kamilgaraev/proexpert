<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Http\UploadedFile;

final class LegalDocumentFilePolicy
{
    /** @param array<string, mixed>|null $configuration */
    public function __construct(private readonly ?array $configuration = null) {}

    public function assertUploadAllowed(UploadedFile $upload): void
    {
        $configuration = $this->configuration ?? (array) config('file-uploads.legal_archive', []);
        $extension = mb_strtolower($upload->getClientOriginalExtension());
        $allowedExtensions = array_map('mb_strtolower', (array) ($configuration['allowed_extensions'] ?? []));
        $maxSize = (int) ($configuration['max_size_bytes'] ?? 0);
        $size = (int) ($upload->getSize() ?: 0);
        $detectedMime = $upload->getMimeType();
        $allowedMimeTypes = (array) (($configuration['allowed_mime_types'] ?? [])[$extension] ?? []);

        if (
            ! $upload->isValid()
            || $extension === ''
            || ! in_array($extension, $allowedExtensions, true)
            || $maxSize < 1
            || $size < 1
            || $size > $maxSize
            || ! is_string($detectedMime)
            || ! in_array($detectedMime, $allowedMimeTypes, true)
        ) {
            throw new LegalDocumentFileRejected($this->message('file_invalid'));
        }
    }

    public function assertDownloadAllowed(
        LegalArchiveDocumentVersion $version,
        User $actor,
        string $purpose,
    ): void {
        $documentFile = $version->documentFile;
        $document = $documentFile?->document;
        $organizationId = (int) $version->organization_id;

        if (
            ! in_array($purpose, ['preview', 'download'], true)
            || $version->processing_status !== 'ready'
            || $organizationId < 1
            || (int) $actor->current_organization_id !== $organizationId
            || ! $documentFile instanceof \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile
            || (int) $documentFile->organization_id !== $organizationId
            || ! $document instanceof \App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument
            || (int) $document->organization_id !== $organizationId
            || ! str_starts_with((string) $version->file_path, "org-{$organizationId}/")
        ) {
            throw new AuthorizationException($this->message('file_access_denied'));
        }
    }

    private function message(string $key): string
    {
        if (Container::getInstance()->bound('translator')) {
            return trans_message("legal_archive.messages.{$key}");
        }

        return "legal_archive.messages.{$key}";
    }
}
