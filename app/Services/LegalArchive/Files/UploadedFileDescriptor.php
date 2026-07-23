<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use App\Services\LegalArchive\CanonicalJson;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final readonly class UploadedFileDescriptor
{
    public function __construct(
        public string $originalName,
        public int $sizeBytes,
        public string $contentHash,
        public ?string $clientMimeType,
        public ?string $detectedMimeType,
    ) {}

    public static function fromUpload(UploadedFile $file): self
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            throw new RuntimeException('legal_document_request_file_unavailable');
        }
        $hash = hash_file('sha256', $path);
        if (! is_string($hash)) {
            throw new RuntimeException('legal_document_request_file_hash_failed');
        }

        return new self(
            $file->getClientOriginalName(),
            (int) ($file->getSize() ?: filesize($path)),
            $hash,
            $file->getClientMimeType() ?: null,
            $file->getMimeType() ?: null,
        );
    }

    public function contentIdentity(): string
    {
        return CanonicalJson::fingerprint([
            'content_hash' => $this->contentHash,
            'size_bytes' => $this->sizeBytes,
        ]);
    }

    public function toArray(): array
    {
        return [
            'original_name' => $this->originalName,
            'size_bytes' => $this->sizeBytes,
            'content_hash' => $this->contentHash,
            'client_mime_type' => $this->clientMimeType,
            'detected_mime_type' => $this->detectedMimeType,
        ];
    }
}
