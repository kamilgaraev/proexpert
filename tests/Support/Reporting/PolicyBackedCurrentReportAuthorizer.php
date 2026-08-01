<?php

declare(strict_types=1);

namespace Tests\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportCatalogAuthorization;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use DateTimeZone;

final readonly class PolicyBackedCurrentReportAuthorizer implements CurrentReportExactManyAuthorizer, CurrentReportScopeAuthorizer
{
    public function __construct(
        private ReportDefinitionVisibilityResolver $visibilityResolver,
        private array $permissions,
    ) {}

    public function authorizeForOrganization(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        return $this->authorizeExact(
            $actorId,
            new ReportScope($organizationId, [$organizationId], [], [], $timezone),
            $target,
        );
    }

    public function authorizeCatalog(
        int $actorId,
        int $organizationId,
        DateTimeZone $timezone,
        array $targets,
    ): ReportCatalogAuthorization {
        $scope = new ReportScope($organizationId, [$organizationId], [], [], $timezone);
        $authorizations = [];
        $context = null;
        foreach ($targets as $target) {
            $authorization = $this->authorization($actorId, $scope, $target, false);
            if (! $authorization->visibility->canView) {
                continue;
            }
            $authorizations[$target->definition->definitionHash->value] = $authorization;
            $context ??= new ReportExecutionContext(
                $authorization->actor,
                $scope,
                $authorization->visibility,
                $authorization->decision,
            );
        }
        if (! $context instanceof ReportExecutionContext) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }

        return new ReportCatalogAuthorization($context, $authorizations);
    }

    public function authorizeExact(
        int $actorId,
        ReportScope $requestedScope,
        CurrentReportAuthorizationTarget $target,
    ): CurrentReportAuthorization {
        return $this->authorization($actorId, $requestedScope, $target, true);
    }

    public function authorizeExactMany(int $actorId, ReportScope $requestedScope, array $targets): array
    {
        return array_map(
            fn (CurrentReportAuthorizationTarget $target): CurrentReportAuthorization => $this->authorization(
                $actorId,
                $requestedScope,
                $target,
                true,
            ),
            $targets,
        );
    }

    private function authorization(
        int $actorId,
        ReportScope $scope,
        CurrentReportAuthorizationTarget $target,
        bool $assertOperation,
    ): CurrentReportAuthorization {
        $visibility = $this->visibilityResolver->resolve(
            $scope->organizationId,
            $target->definition,
            $target->operation,
            $target->exportFormat,
            fn (string $permission): bool => in_array($permission, $this->permissions, true),
        );
        if ($assertOperation && ! $this->operationAllowed($target->operation, $visibility)) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
        }
        $actor = new ReportActor($actorId, 'active', []);
        $decision = new AuthorizationDecisionContext(
            'queue',
            $scope->organizationId,
            $scope->holdingOrganizationIds,
            $scope->projectIds,
            $scope->resources,
            $scope->timezone,
            'policy-backed-current-authorizer',
            null,
        );

        return new CurrentReportAuthorization($actor, $decision, $visibility, $target);
    }

    private function operationAllowed(
        ReportOperation $operation,
        \App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility $visibility,
    ): bool {
        return match ($operation) {
            ReportOperation::VIEW, ReportOperation::DRILL_DOWN => $visibility->canView,
            ReportOperation::RUN => $visibility->canRun,
            ReportOperation::EXPORT => $visibility->canExport,
            ReportOperation::DOWNLOAD => $visibility->canDownload,
            ReportOperation::MANAGE => $visibility->canManage,
            ReportOperation::VIEW_SENSITIVE => $visibility->canViewSensitive,
            ReportOperation::VIEW_AUDIT => $visibility->canViewAudit,
        };
    }
}
