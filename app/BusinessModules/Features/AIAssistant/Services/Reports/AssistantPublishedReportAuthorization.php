<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportProjectScopeResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use DateTimeZone;
use InvalidArgumentException;

final readonly class AssistantPublishedReportAuthorization
{
    public function __construct(
        private CurrentReportScopeAuthorizer $authorizer,
        private CurrentReportProjectScopeResolver $projects,
        private ReportHttpAuthorizationTargetResolver $targets,
        private ReportExecutionContextFactory $contexts,
        private ReportHttpAuthorizationOrchestrator $aggregates,
    ) {}

    public function createRun(int $actorId, int $organizationId, array $parameters): ReportExecutionContext
    {
        $target = $this->targets->createRun($parameters['report_code']);
        if (! hash_equals($parameters['definition_sha256'], $target->definition->definitionHash->value)) {
            throw new InvalidArgumentException('assistant_report_definition_changed');
        }
        $timezone = new DateTimeZone($parameters['timezone']);
        $projectId = $parameters['project_id'];
        $authorization = $projectId === null
            ? $this->authorizer->authorizeForOrganization($actorId, $organizationId, $timezone, $target)
            : $this->authorizer->authorizeExact($actorId, $this->projects->resolveForProject($actorId, $organizationId, $projectId, $timezone), $target);

        return $this->contexts->fromCurrentAuthorization($authorization);
    }

    public function run(int $actorId, int $organizationId, string $runId): ReportExecutionContext
    {
        return $this->context($this->aggregates->runForActor($actorId, $organizationId, $runId));
    }

    public function createExport(int $actorId, int $organizationId, string $runId, string $format): ReportExecutionContext
    {
        return $this->context($this->aggregates->runForActor($actorId, $organizationId, $runId, ReportOperation::EXPORT, $format));
    }

    public function export(int $actorId, int $organizationId, string $exportId, ReportOperation $operation = ReportOperation::VIEW): ReportExecutionContext
    {
        return $this->context($this->aggregates->exportForActor($actorId, $organizationId, $exportId, $operation));
    }

    private function context(array $authorization): ReportExecutionContext
    {
        $context = $authorization['context'] ?? null;
        if (! $context instanceof ReportExecutionContext) {
            throw new InvalidArgumentException('assistant_report_context_invalid');
        }

        return $context;
    }
}
