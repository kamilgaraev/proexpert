<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentSignature;

final class DisabledElectronicSignatureProvider implements ElectronicSignatureProvider
{
    public function start(SignatureContext $context): SignatureSession
    {
        throw new ElectronicSignatureUnavailable;
    }

    public function complete(SignatureCallback $callback): SignatureVerificationResult
    {
        throw new ElectronicSignatureUnavailable;
    }

    public function verify(LegalDocumentSignature $signature): SignatureVerificationResult
    {
        throw new ElectronicSignatureUnavailable;
    }
}
