<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use DateTimeImmutable;

final readonly class ReportAuthorizationFactSetFactory
{
    /** @return list<CurrentReportAuthorizationFacts> */
    public function forScope(int $actorId, ReportScope $scope, DateTimeImmutable $occurredAt): array
    {
        $facts = [];
        $coveredProjectIds = [];

        foreach ($scope->resources as $resource) {
            $facts[] = new CurrentReportAuthorizationFacts(
                'queue',
                $actorId,
                $scope->organizationId,
                $resource->projectId,
                $resource,
                $occurredAt,
            );
            if ($resource->projectId !== null) {
                $coveredProjectIds[$resource->projectId] = true;
            }
        }

        foreach ($scope->projectIds as $projectId) {
            if (isset($coveredProjectIds[$projectId])) {
                continue;
            }
            $facts[] = new CurrentReportAuthorizationFacts(
                'queue',
                $actorId,
                $scope->organizationId,
                $projectId,
                null,
                $occurredAt,
            );
        }

        if ($facts === []) {
            $facts[] = new CurrentReportAuthorizationFacts(
                'queue',
                $actorId,
                $scope->organizationId,
                null,
                null,
                $occurredAt,
            );
        }

        return $facts;
    }
}
