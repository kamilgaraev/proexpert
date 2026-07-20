<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

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

    public function verify(SignatureVerificationContext $context): SignatureVerificationResult
    {
        throw new ElectronicSignatureUnavailable;
    }
}
