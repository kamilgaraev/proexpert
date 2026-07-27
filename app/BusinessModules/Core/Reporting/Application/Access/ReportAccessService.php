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
        $canView = $this->has($permissions, ['reports.view'])
            && $this->has($permissions, $policy->viewPermissions);
        $canExport = $canView
            && $this->has($permissions, ['reports.export'])
            && $this->has($permissions, $policy->exportPermissions);

        $visibility = new ReportVisibility(
            $canView,
            $canView && $this->has($permissions, ['reports.run']),
            $canExport,
            $canExport && $this->has($permissions, ['reports.download']),
            $canView && $this->has($permissions, ['reports.manage']),
            $canView
                && $this->has($permissions, ['reports.sensitive'])
                && $this->has($permissions, $policy->sensitivePermissions),
            $canView
                && $this->has($permissions, ['reports.audit'])
                && $this->has($permissions, $policy->auditPermissions),
        );

        $allowed = match ($operation) {
            ReportOperation::VIEW => $visibility->canView,
            ReportOperation::RUN => $visibility->canRun,
            ReportOperation::EXPORT => $visibility->canExport,
            ReportOperation::DOWNLOAD => $visibility->canDownload,
            ReportOperation::MANAGE => $visibility->canManage,
            ReportOperation::VIEW_SENSITIVE,
            ReportOperation::VIEW_AUDIT => $visibility->canView,
            ReportOperation::DRILL_DOWN => $visibility->canView,
        };

        if (!$allowed) {
            $this->deny();
        }

        if ($operation === ReportOperation::DRILL_DOWN || ($operation === ReportOperation::VIEW && $source !== null)) {
            if ($source === null || !$this->canAccessSource($context, $source)) {
                $this->deny();
            }
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
        } catch (ReportContractException $exception) {
            throw $exception;
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

    private function canAccessSource(ReportExecutionContext $context, ReportSourceRef $source): bool
    {
        try {
            return $this->sourceAccessResolver->canAccess($context, $source);
        } catch (Throwable) {
            return false;
        }
    }

    private function deny(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SCOPE_FORBIDDEN);
    }
}
