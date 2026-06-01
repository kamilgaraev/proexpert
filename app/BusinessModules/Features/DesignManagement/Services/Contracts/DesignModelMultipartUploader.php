<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services\Contracts;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;

interface DesignModelMultipartUploader
{
    public function start(DesignPackage $package, int $userId, array $payload): array;

    public function complete(int $organizationId, int $userId, string $uploadId): DesignArtifactVersion;

    public function abort(int $organizationId, int $userId, string $uploadId): void;
}
