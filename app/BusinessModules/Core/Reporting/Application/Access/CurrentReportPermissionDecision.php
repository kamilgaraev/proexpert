<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use InvalidArgumentException;

final readonly class CurrentReportPermissionDecision
{
    public function __construct(
        public int $actorId,
        public string $permission,
        public int $organizationId,
        public ?int $projectId,
        public ?ReportScopedResource $resource,
        public bool $granted,
    ) {
        if ($actorId < 1
            || preg_match('/^[a-z0-9][a-z0-9._-]+$/D', $permission) !== 1
            || $organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || ($resource?->projectId !== null && $resource->projectId !== $projectId)) {
            throw new InvalidArgumentException('current_report_permission_decision_invalid');
        }
    }
}
