<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services\Contracts;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;

interface DesignModelRegistrationService
{
    public function ensurePackageAcceptsModelChanges(DesignPackage $package): void;

    public function findPackage(int $organizationId, int $packageId): ?DesignPackage;

    public function registerStoredIfcModel(
        DesignPackage $package,
        int $userId,
        string $sourcePath,
        array $fileInfo,
        array $payload,
    ): DesignArtifactVersion;
}
