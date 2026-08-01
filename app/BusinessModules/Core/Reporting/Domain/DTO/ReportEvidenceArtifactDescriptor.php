<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;

final readonly class ReportEvidenceArtifactDescriptor
{
    public function __construct(public string $id, public string $plan, public string $kind, public string $relativePath, public Sha256Hash $sha256) {}
}
