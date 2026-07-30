<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class TrackedPlanDocument
{
    public function __construct(public string $relativePath, public string $commitSha, public Sha256Hash $bytesHash, public string $bytes) {}
}
