<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Files;

use RuntimeException;
use Throwable;

final class LegalDocumentVersionPersistenceFailed extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
