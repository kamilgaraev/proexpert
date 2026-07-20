<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

interface ElectronicSignatureProvider
{
    public function start(SignatureContext $context): SignatureSession;

    public function complete(SignatureCallback $callback): SignatureVerificationResult;

    public function verify(SignatureVerificationContext $context): SignatureVerificationResult;
}
