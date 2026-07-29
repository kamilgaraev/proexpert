<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CurrentReportAuthorizationFacts
{
    public function __construct(
        public string $channel,
        public int $actorId,
        public int $organizationId,
        public ?int $projectId,
        public ?ReportScopedResource $resource,
        public DateTimeImmutable $occurredAt,
    ) {
        if ($channel !== 'queue'
            || $actorId < 1
            || $organizationId < 1
            || ($projectId !== null && $projectId < 1)
            || ($resource?->projectId !== null && $resource->projectId !== $projectId)) {
            throw new InvalidArgumentException('current_report_authorization_facts_invalid');
        }
    }
}
