<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;

final readonly class SignatureVerificationContext
{
    public function __construct(
        public LegalDocumentSignature $signature,
        public SignatureArtifact $artifact,
        public string $storageVersionId,
        public ?string $storageEtag,
    ) {}
}
