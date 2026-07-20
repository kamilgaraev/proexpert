<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use DomainException;

final readonly class SignatureArtifact
{
    public function __construct(
        public string $content,
        public string $originalName,
        public string $declaredMimeType,
    ) {
        if ($content === '' || strlen($content) > 20 * 1024 * 1024
            || trim($originalName) === '' || mb_strlen($originalName) > 255
            || trim($declaredMimeType) === '' || mb_strlen($declaredMimeType) > 127) {
            throw new DomainException('legal_signature_container_invalid');
        }
    }

    public function sha256(): string
    {
        return hash('sha256', $this->content);
    }
}
