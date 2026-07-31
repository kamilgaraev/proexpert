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
use Throwable;

final readonly class ReportAccessService
{
    public function __construct(
        private ReportActorLoader $actorLoader,
        private ReportSourceAccessResolver $sourceAccessResolver,
    ) {
    }

    public function assertOperation(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        ReportOperation $operation,
        ?ReportSourceRef $source,
    ): ReportVisibility {
        $actor = $this->reloadActor($context);
        $permissions = array_fill_keys($actor->permissionSlugs, true);
        $policy = $definition->permissionPolicy;
        $canView = $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW))
            && $this->has($permissions, $policy->viewPermissions);
        $canExport = $canView
            && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::EXPORT))
            && $this->has($permissions, $policy->exportPermissions);

        $visibility = new ReportVisibility(
            $canView,
            $canView && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::RUN)),
            $canExport,
            $canExport && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::DOWNLOAD)),
            $canView && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::MANAGE)),
            $canView
                && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW_SENSITIVE))
                && $this->has($permissions, $policy->sensitivePermissions),
            $canView
                && $this->has($permissions, ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW_AUDIT))
                && $this->has($permissions, $policy->auditPermissions),
        );

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

        if (!$allowed) {
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

    private function has(array $permissions, array $required): bool
    {
        foreach ($required as $permission) {
            if (!isset($permissions[$permission])) {
                return false;
            }
        }

        return true;
    }

    private function assertSourceAccessible(
        ReportExecutionContext $context,
        ReportDefinition $definition,
        ReportSourceRef $source,
    ): void
    {
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
