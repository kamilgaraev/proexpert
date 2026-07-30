<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Access;

use App\BusinessModules\Core\Reporting\Application\Access\CurrentReportAuthorizationFacts;
use App\BusinessModules\Core\Reporting\Application\Access\ReportScopedResourceAccessDecision;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportScopedResourceAuthorizer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScopedResource;
use App\Models\User;
use Closure;
use InvalidArgumentException;

final readonly class QueryReportScopedResourceAuthorizer implements ReportScopedResourceAuthorizer
{
    public function __construct(
        private string $resourceKind,
        private Closure $exists,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $resourceKind) !== 1) {
            throw new InvalidArgumentException('report_resource_authorizer_invalid');
        }
    }

    public function kind(): string
    {
        return $this->resourceKind;
    }

    public function authorize(
        User $actor,
        int $organizationId,
        ReportScopedResource $resource,
        CurrentReportAuthorizationFacts $facts,
    ): ReportScopedResourceAccessDecision {
        $actorId = (int) $actor->getAuthIdentifier();
        $factsMatch = $facts->actorId === $actorId
            && $facts->organizationId === $organizationId
            && $facts->projectId === $resource->projectId
            && $facts->resource?->canonicalIdentity() === $resource->canonicalIdentity()
            && hash_equals($resource->kind, $this->resourceKind);
        $granted = $factsMatch
            && $resource->projectId !== null
            && (bool) ($this->exists)($organizationId, $resource->projectId, $resource->id);

        return new ReportScopedResourceAccessDecision(
            $actorId,
            $organizationId,
            $resource->projectId,
            $resource->kind,
            $resource->id,
            $granted,
        );
    }
}
