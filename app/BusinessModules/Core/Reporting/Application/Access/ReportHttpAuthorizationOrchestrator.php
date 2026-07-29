<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportAuthorizationSubjectReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportHttpAuthorizationTargetResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchAggregate;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorization;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use Closure;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final readonly class ReportHttpAuthorizationOrchestrator
{
    public function __construct(
        private ConnectionInterface $database,
        private ReportExecutionContextFactory $contexts,
        private ReportHttpAuthorizationTargetResolver $targets,
        private ReportAuthorizationSubjectReader $subjects,
        private CurrentReportScopeAuthorizer $authorizer,
    ) {}

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function createRun(Request $request, string $reportCode): array
    {
        return $this->transaction(function () use ($request, $reportCode): array {
            $facts = $this->contexts->httpFacts($request);
            $target = $this->targets->createRun($reportCode);

            return $this->organizationAuthorization($facts, $target);
        });
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function showRun(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::VIEW);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function retryRun(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::RUN);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function cancelRun(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::RUN);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function rows(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::VIEW);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function drillDown(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::DRILL_DOWN);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function createExport(Request $request, string $runId): array
    {
        return $this->runAuthorization($request, $runId, ReportOperation::EXPORT, true);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function showExport(Request $request, string $exportId): array
    {
        return $this->exportAuthorization($request, $exportId, ReportOperation::VIEW);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function retryExport(Request $request, string $exportId): array
    {
        return $this->exportAuthorization($request, $exportId, ReportOperation::EXPORT);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function cancelExport(Request $request, string $exportId): array
    {
        return $this->exportAuthorization($request, $exportId, ReportOperation::EXPORT);
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    public function download(Request $request, string $exportId): array
    {
        return $this->exportAuthorization($request, $exportId, ReportOperation::DOWNLOAD);
    }

    public function catalog(Request $request): ReportCatalogAuthorization
    {
        return $this->transaction(function () use ($request): ReportCatalogAuthorization {
            $facts = $this->contexts->httpFacts($request);
            $targets = $this->targets->catalog();
            $authorization = $this->authorizer->authorizeCatalog(
                $facts['actor_id'],
                $facts['organization_id'],
                new DateTimeZone('UTC'),
                $targets,
            );
            $expected = [];
            foreach ($targets as $target) {
                $expected[$target->definition->definitionHash->value] = $target->canonicalFingerprint();
            }
            foreach ($authorization->authorizations as $hash => $definitionAuthorization) {
                if (($expected[$hash] ?? null) !== $definitionAuthorization->target->canonicalFingerprint()) {
                    throw new InvalidArgumentException('report_catalog_authorization_invalid');
                }
            }
            if ($authorization->context->actor->id !== $facts['actor_id']) {
                throw new InvalidArgumentException('report_catalog_authorization_invalid');
            }

            return $authorization;
        });
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    private function runAuthorization(
        Request $request,
        string $runId,
        ReportOperation $operation,
        bool $createExport = false,
    ): array {
        return $this->transaction(function () use ($request, $runId, $operation, $createExport): array {
            $facts = $this->contexts->httpFacts($request);
            $subject = $this->subjects->run($runId);
            if ($subject->aggregateKind !== ReportDispatchAggregate::RUN || $subject->aggregateId !== $runId) {
                throw new InvalidArgumentException('report_http_authorization_mismatch');
            }
            $target = $createExport
                ? $this->targets->createExport($runId)
                : $this->targets->run($runId, $operation);

            $this->assertTargetMatchesSubject($target, $subject, $operation);

            return $this->exactAuthorization(
                $facts['actor_id'],
                $facts['organization_id'],
                $subject,
                $target,
            );
        });
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    private function exportAuthorization(Request $request, string $exportId, ReportOperation $operation): array
    {
        return $this->transaction(function () use ($request, $exportId, $operation): array {
            $facts = $this->contexts->httpFacts($request);
            $subject = $this->subjects->export($exportId);
            if ($subject->aggregateKind !== ReportDispatchAggregate::EXPORT || $subject->aggregateId !== $exportId) {
                throw new InvalidArgumentException('report_http_authorization_mismatch');
            }
            $target = $this->targets->export($exportId, $operation);
            $this->assertTargetMatchesSubject($target, $subject, $operation);

            return $this->exactAuthorization(
                $facts['actor_id'],
                $facts['organization_id'],
                $subject,
                $target,
            );
        });
    }

    /**
     * @param  array{actor_id:int,organization_id:int}  $facts
     * @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization}
     */
    private function organizationAuthorization(array $facts, CurrentReportAuthorizationTarget $target): array
    {
        $authorization = $this->authorizer->authorizeForOrganization(
            $facts['actor_id'],
            $facts['organization_id'],
            new DateTimeZone('UTC'),
            $target,
        );
        $this->assertAuthorizationMatches($facts['actor_id'], $authorization, $target);

        return [
            'context' => $this->contexts->fromCurrentAuthorization($authorization),
            'authorization' => $authorization,
        ];
    }

    /** @return array{context:ReportExecutionContext,authorization:CurrentReportAuthorization} */
    private function exactAuthorization(
        int $actorId,
        int $organizationId,
        ReportAuthorizationSubject $subject,
        CurrentReportAuthorizationTarget $target,
    ): array {
        if ($subject->scope->organizationId !== $organizationId) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }

        $authorization = $this->authorizer->authorizeExact($actorId, $subject->scope, $target);
        $this->assertAuthorizationMatches($actorId, $authorization, $target);
        if ($authorization->decision->organizationId !== $subject->scope->organizationId) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }

        $context = $this->contexts->fromCurrentAuthorization($authorization);
        if ($context->scope->canonicalIdentity() !== $subject->scope->canonicalIdentity()) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }

        return ['context' => $context, 'authorization' => $authorization];
    }

    private function assertAuthorizationMatches(
        int $actorId,
        CurrentReportAuthorization $authorization,
        CurrentReportAuthorizationTarget $target,
    ): void {
        if (
            $authorization->actor->id !== $actorId
            || $authorization->target->canonicalFingerprint() !== $target->canonicalFingerprint()
        ) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }
    }

    private function assertTargetMatchesSubject(
        CurrentReportAuthorizationTarget $target,
        ReportAuthorizationSubject $subject,
        ReportOperation $operation,
    ): void {
        $expectedSnapshot = $operation === ReportOperation::RUN ? null : $subject->snapshot;
        $expected = new CurrentReportAuthorizationTarget(
            $subject->definition,
            $operation,
            $expectedSnapshot,
        );
        if (
            $target->canonicalFingerprint() !== $expected->canonicalFingerprint()
        ) {
            throw new InvalidArgumentException('report_http_authorization_mismatch');
        }
    }

    private function transaction(Closure $callback): mixed
    {
        try {
            return $this->database->transaction(function () use ($callback): mixed {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

                return $callback();
            });
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }
}
