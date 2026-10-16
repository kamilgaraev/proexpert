<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use DateTimeZone;
use RuntimeException;

final readonly class ReportSubscriptionExecutionContextFactory
{
    public function __construct(
        private CurrentReportScopeAuthorizer $authorizer,
        private ReportExecutionContextFactory $contexts,
        private ReportDefinitionRegistry $definitions,
        private CurrentReportProjectScopeResolver $projectScopes,
    ) {}

    public function forSubscription(
        ReportSubscription $subscription,
        ReportOperation $operation,
        ?ReportSubscriptionExecutionInput $input = null,
    ): ReportExecutionContext {
        $input ??= ReportSubscriptionExecutionInput::fromCanonicalBytes($subscription->executionInputBytes);
        $definition = $this->definitions->published($input->reportCode)->payload();
        if (! hash_equals($input->definitionHash->value, $definition->definitionHash->value)
            || $input->contractVersion !== $definition->contractVersion) {
            throw new RuntimeException('report_subscription_definition_changed');
        }
        $projectId = $input->effectiveProjectId();
        if ($projectId !== null || $definition->code === 'accepted_production_progress') {
            if ($projectId === null) {
                throw new RuntimeException('report_subscription_project_scope_invalid');
            }

            return $this->forProject($subscription->ownerId, $subscription->organizationId, $projectId,
                $input->timezone, $definition, $operation);
        } else {
            $authorization = $this->authorizer->authorizeForOrganization(
                $subscription->ownerId, $subscription->organizationId, $input->timezone,
                new CurrentReportAuthorizationTarget($definition, $operation, null),
            );
        }

        return $this->contexts->fromCurrentAuthorization($authorization);
    }

    public function forProject(
        int $actorId,
        int $organizationId,
        int $projectId,
        DateTimeZone $timezone,
        ReportDefinition $definition,
        ReportOperation $operation,
    ): ReportExecutionContext {
        if ($projectId < 1) {
            throw new RuntimeException('report_subscription_project_scope_invalid');
        }
        $authorization = $this->authorizer->authorizeExact($actorId,
            $this->projectScopes->resolveForProject($actorId, $organizationId, $projectId, $timezone),
            new CurrentReportAuthorizationTarget($definition, $operation, null));

        return $this->contexts->fromCurrentAuthorization($authorization);
    }
}
