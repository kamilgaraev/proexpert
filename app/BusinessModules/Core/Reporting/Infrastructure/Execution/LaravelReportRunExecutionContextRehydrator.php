<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Execution;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportScopeAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunAsyncContextSeedReader;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportRunExecutionContextRehydrator;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use App\BusinessModules\Core\Reporting\Domain\DTO\AuthorizationDecisionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use Illuminate\Support\Str;
use Throwable;

final readonly class LaravelReportRunExecutionContextRehydrator implements ReportRunExecutionContextRehydrator
{
    public function __construct(
        private ReportRunAsyncContextSeedReader $seeds,
        private CurrentReportScopeAuthorizer $authorizer,
    ) {}

    public function forRun(string $runId): ReportExecutionContext
    {
        try {
            $seed = $this->seeds->forRun($runId);
            if ($seed->aggregateKind !== 'run' || $seed->aggregateId !== $runId) {
                throw new \RuntimeException('report_async_seed_identity_mismatch');
            }
            $target = new CurrentReportAuthorizationTarget($seed->definition, ReportOperation::RUN, null);
            $current = $this->authorizer->authorizeExact($seed->requesterActorId, $seed->requestedScope, $target);
            if ($current->actor->id !== $seed->requesterActorId
                || $current->decision->organizationId !== $seed->organizationId
                || $current->decision->holdingOrganizationIds !== $seed->requestedScope->holdingOrganizationIds
                || $current->decision->projectIds !== $seed->requestedScope->projectIds
                || array_map(static fn ($resource): array => $resource->canonicalIdentity(), $current->decision->resources)
                    !== array_map(static fn ($resource): array => $resource->canonicalIdentity(), $seed->requestedScope->resources)
                || $current->decision->timezone->getName() !== $seed->requestedScope->timezone->getName()
                || ! hash_equals($current->target->canonicalFingerprint(), $target->canonicalFingerprint())
                || ! $current->visibility->canRun) {
                throw new \RuntimeException('report_async_current_authorization_mismatch');
            }
            $authorization = new AuthorizationDecisionContext(
                'queue',
                $current->decision->organizationId,
                $current->decision->holdingOrganizationIds,
                $current->decision->projectIds,
                $current->decision->resources,
                $seed->requestedScope->timezone,
                (string) Str::uuid(),
                ['job' => 'materialize_report_run', 'lineage_id' => $seed->correlationLineageId],
            );

            return new ReportExecutionContext(
                $current->actor,
                $seed->requestedScope,
                $current->visibility,
                $authorization,
            );
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN, previous: $exception);
        }
    }
}
