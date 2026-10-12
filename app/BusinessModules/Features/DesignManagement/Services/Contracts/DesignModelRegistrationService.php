<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services\Contracts;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\Models\User;

interface DesignModelRegistrationService
{
    public function ensurePackageAcceptsModelChanges(DesignPackage $package, User|int|null $actor = null, ?int $artifactId = null): void;

    public function findPackage(int $organizationId, int $packageId, User|int|null $actor = null, string $permission = 'design-management.view'): ?DesignPackage;

    public function registerStoredIfcModel(
        DesignPackage $package,
        int $userId,
        string $sourcePath,
        array $fileInfo,
        array $payload,
    ): DesignArtifactVersion;
}
