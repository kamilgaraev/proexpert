<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportActor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\CurrentReportExactManyAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Execution\CurrentReportAuthorizationTarget;
use Throwable;

final readonly class ReportAccessService
{
    public function __construct(
        private ReportActorLoader $actorLoader,
        private ReportSourceAccessResolver $sourceAccessResolver,
        private ReportDefinitionVisibilityResolver $visibilityResolver,
        private ?CurrentReportExactManyAuthorizer $currentAuthorizer = null,
    ) {}

    public function assertOperation(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        ReportOperation $operation,
        ?ReportSourceRef $source,
        ?string $exportFormat = null,
    ): ReportVisibility {
        if ($context->grant !== null) {
            if (! $context->grant->matches($definition, $operation, $exportFormat)) {
                $this->deny();
            }

            $visibility = $context->visibility;
        } elseif ($this->currentAuthorizer !== null && ! in_array($operation, [ReportOperation::EXPORT, ReportOperation::DOWNLOAD, ReportOperation::DRILL_DOWN], true)) {
            $target = new CurrentReportAuthorizationTarget($definition, $operation, null, $exportFormat);
            $authorizations = $this->currentAuthorizer->authorizeExactMany($context->actor->id, $context->scope, [$target]);
            if (count($authorizations) !== 1 || ! isset($authorizations[0])
                || $authorizations[0]->actor->id !== $context->actor->id
                || $authorizations[0]->target->canonicalFingerprint() !== $target->canonicalFingerprint()) { $this->deny(); }
            $current = (new ReportExecutionContextFactory)->fromCurrentAuthorization($authorizations[0]);
            if ($current->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) { $this->deny(); }
            $visibility = $current->visibility;
        } else {
            $actor = $this->reloadActor($context);
            $visibility = $this->legacyVisibility($context, $definition, $operation, $exportFormat, $actor);
        }

        $allowed = match ($operation) {
            ReportOperation::VIEW => $visibility->canView,
            ReportOperation::RUN => $visibility->canRun,
            ReportOperation::EXPORT => $visibility->canExport,
            ReportOperation::DOWNLOAD => $visibility->canDownload,
            ReportOperation::MANAGE => $visibility->canManage,
            ReportOperation::VIEW_SENSITIVE => $visibility->canViewSensitive,
            ReportOperation::VIEW_AUDIT => $visibility->canViewAudit,
            ReportOperation::DRILL_DOWN => $visibility->canView,
        };

        if (! $allowed) {
            $this->deny();
        }

        if ($operation === ReportOperation::DRILL_DOWN || ($operation === ReportOperation::VIEW && $source !== null)) {
            if ($source === null) {
                $this->deny();
            }

            $this->assertSourceAccessible($context, $definition, $source);
        }

        return $visibility;
    }

    private function legacyVisibility(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        ReportOperation $operation,
        ?string $exportFormat,
        ReportActor $actor,
    ): ReportVisibility {
        if (! $this->visibilityResolver->moduleAccessDecision($context->scope->organizationId)->allows($context->scope->organizationId, $definition)) {
            return new ReportVisibility(false, false, false, false, false, false, false);
        }
        $permissions = array_fill_keys($actor->permissionSlugs, true);

        return $this->visibilityResolver->resolve(
            $context->scope->organizationId,
            $definition,
            $operation,
            $exportFormat,
            static fn (string $permission): bool => isset($permissions[$permission]),
        );
    }

    private function reloadActor(ReportExecutionContext $context): ReportActor
    {
        try {
            $actor = $this->actorLoader->loadActive($context->actor->id);
            if ($actor->id !== $context->actor->id) {
                $this->deny();
            }

            return $actor;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }

    private function assertSourceAccessible(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        ReportSourceRef $source,
    ): void {
        try {
            $this->sourceAccessResolver->assertAccessible($context, $definition, $source);
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SCOPE_FORBIDDEN,
                previous: $exception,
            );
        }
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}
