<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Signatures;

use RuntimeException;

final class ElectronicSignatureUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(trans_message('legal_archive.signatures.provider_unavailable'));
    }

    public function statusCode(): int
    {
        return 503;
    }
}
