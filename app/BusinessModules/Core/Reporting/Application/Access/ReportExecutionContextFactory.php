<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportAuthorizationGrant;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class ReportExecutionContextFactory
{
    /** @return array{actor_id:int,organization_id:int} */
    public function httpFacts(Request $request): array
    {
        $authenticated = $request->user('api_admin');
        if (! is_object($authenticated) || ! method_exists($authenticated, 'getAuthIdentifier')) {
            throw new InvalidArgumentException('report_http_authorization_facts_invalid');
        }

        $actorId = (int) $authenticated->getAuthIdentifier();
        $organizationId = (int) $request->attributes->get('current_organization_id');
        if ($actorId < 1 || $organizationId < 1) {
            throw new InvalidArgumentException('report_http_authorization_facts_invalid');
        }

        return [
            'actor_id' => $actorId,
            'organization_id' => $organizationId,
        ];
    }

    public function fromCurrentAuthorization(
        CurrentReportAuthorization $authorization,
    ): ReportExecutionContext {
        $decision = $authorization->decision;
        $scope = new ReportScope(
            $decision->organizationId,
            $decision->holdingOrganizationIds,
            $decision->projectIds,
            $decision->resources,
            $decision->timezone,
        );

        if (
            $authorization->actor->id < 1
            || (
                $authorization->target->snapshot !== null
                && $authorization->target->snapshot->scope->canonicalIdentity() !== $scope->canonicalIdentity()
            )
        ) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }

        return new ReportExecutionContext(
            $authorization->actor,
            $scope,
            $authorization->visibility,
            $decision,
            new ReportAuthorizationGrant(
                $authorization->target->definition->definitionHash->value,
                $authorization->target->operation,
                $authorization->target->exportFormat,
            ),
        );
    }
}
