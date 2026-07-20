<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;

interface ElectronicSignatureProvider
{
    public function start(SignatureContext $context): SignatureSession;

    public function complete(SignatureCallback $callback): SignatureVerificationResult;

    public function verify(LegalDocumentSignature $signature): SignatureVerificationResult;
}
