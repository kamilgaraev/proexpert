<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\TrackedPlanDocument;

interface TrackedRepositoryFileReader
{
    public function read(string $repositoryRoot, string $relativePath, string $commitSha): TrackedPlanDocument;
}
