<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Editor;

use DateTimeImmutable;

final readonly class EditorDocumentContext
{
    public function __construct(
        public string $sessionId,
        public int $organizationId,
        public int $documentId,
        public int $versionId,
        public int $fileId,
        public int $actorId,
        public int $generation,
        public string $contentHash,
        public string $filename,
        public string $sourceUrl,
        public string $callbackUrl,
        public DateTimeImmutable $expiresAt,
        public string $mode = 'edit',
    ) {}
}
