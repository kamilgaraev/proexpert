<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class ReportPublicationIdentity
{
    public function __construct(
        public string $publicationId,
        public string $code,
        public Sha256Hash $proofHash,
        public string $releaseGitSha,
    ) {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $publicationId) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || preg_match('/^[a-f0-9]{40}$/D', $releaseGitSha) !== 1) {
            throw new InvalidArgumentException('report_publication_identity_invalid');
        }
    }
}
