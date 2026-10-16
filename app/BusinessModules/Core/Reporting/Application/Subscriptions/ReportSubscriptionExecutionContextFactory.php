<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Subscriptions;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscription;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSubscriptionExecutionInput;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
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
        $target = new CurrentReportAuthorizationTarget($definition, $operation, null);
        $projectId = $input->filters->values['project_id'] ?? null;
        if ($projectId !== null || $definition->code === 'accepted_production_progress') {
            $projectId = filter_var($projectId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($projectId === false) {
                throw new RuntimeException('report_subscription_project_scope_invalid');
            }
            $authorization = $this->authorizer->authorizeExact(
                $subscription->ownerId,
                $this->projectScopes->resolveForProject($subscription->ownerId, $subscription->organizationId, $projectId, $input->timezone),
                $target,
            );
        } else {
            $authorization = $this->authorizer->authorizeForOrganization(
                $subscription->ownerId, $subscription->organizationId, $input->timezone, $target,
            );
        }

        return $this->contexts->fromCurrentAuthorization($authorization);
    }
}
