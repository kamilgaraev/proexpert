<?php

declare(strict_types=1);

namespace App\Services\LegalArchive;

use RuntimeException;

final class LegalArchiveLockConflict extends RuntimeException
{
    public function __construct(public readonly int $currentLockVersion)
    {
        parent::__construct('legal_archive_lock_conflict');
    }
}
