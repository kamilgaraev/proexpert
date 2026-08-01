<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use InvalidArgumentException;

final readonly class ReportScopedResourceAccessDecision
{
    public function __construct(
        public int $actorId,
        public int $organizationId,
        public ?int $projectId,
        public string $kind,
        public int $id,
        public bool $granted,
    ) {
        if ($actorId < 1
            || $organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $kind) !== 1
            || $id < 1) {
            throw new InvalidArgumentException('report_scoped_resource_access_decision_invalid');
        }
    }
}
