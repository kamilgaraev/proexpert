<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Access;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportVisibility;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use Closure;

final readonly class ReportDefinitionVisibilityResolver
{
    public function __construct(private ReportDefinitionModuleAuthorizer $modules) {}

    public function moduleAccessDecision(int $organizationId): ReportDefinitionModuleAccessDecision
    {
        return $this->modules->decision($organizationId);
    }

    /** @param Closure(string): bool $permissionGranted */
    public function resolve(
        int $organizationId,
        ReportDefinition $definition,
        ReportOperation $operation,
        ?string $exportFormat,
        Closure $permissionGranted,
        ?ReportDefinitionModuleAccessDecision $moduleAccess = null,
    ): ReportVisibility {
        $moduleAccess ??= $this->moduleAccessDecision($organizationId);
        if (! $moduleAccess->allows($organizationId, $definition)) {
            return $this->denied();
        }

        if ($definition->coreAccessMode === ReportCoreAccessMode::SOURCE_MODULE_REPORT) {
            return $this->sourceModuleVisibility(
                $definition,
                $operation,
                $exportFormat,
                $permissionGranted,
            );
        }

        $policy = $definition->permissionPolicy;
        $canView = $this->allGranted(
            ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW),
            $permissionGranted,
        ) && $this->allGranted($policy->viewPermissions, $permissionGranted);
        $canExport = $canView
            && $this->allGranted(ReportingPermissionMatrix::requiredFor(ReportOperation::EXPORT), $permissionGranted)
            && $this->allGranted($policy->exportPermissions, $permissionGranted);

        return new ReportVisibility(
            $canView,
            $canView && $this->allGranted(
                ReportingPermissionMatrix::requiredFor(ReportOperation::RUN),
                $permissionGranted,
            ),
            $canExport,
            $canExport && $this->allGranted(
                ReportingPermissionMatrix::requiredFor(ReportOperation::DOWNLOAD),
                $permissionGranted,
            ),
            $canView && $this->allGranted(
                ReportingPermissionMatrix::requiredFor(ReportOperation::MANAGE),
                $permissionGranted,
            ),
            $canView
                && $this->allGranted(
                    ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW_SENSITIVE),
                    $permissionGranted,
                )
                && $this->allGranted($policy->sensitivePermissions, $permissionGranted),
            $canView
                && $this->allGranted(
                    ReportingPermissionMatrix::requiredFor(ReportOperation::VIEW_AUDIT),
                    $permissionGranted,
                )
                && $this->allGranted($policy->auditPermissions, $permissionGranted),
        );
    }

    /** @param Closure(string): bool $permissionGranted */
    private function sourceModuleVisibility(
        ReportDefinition $definition,
        ReportOperation $operation,
        ?string $exportFormat,
        Closure $permissionGranted,
    ): ReportVisibility {
        $canView = $this->allGranted(
            $definition->permissionPolicy->viewPermissions,
            $permissionGranted,
        );
        $canExport = $canView && $this->sourceExportAllowed(
            $definition,
            $operation,
            $exportFormat,
            $permissionGranted,
        );

        return new ReportVisibility(
            $canView,
            $canView,
            $canExport,
            $canExport,
            false,
            false,
            false,
        );
    }

    /** @param Closure(string): bool $permissionGranted */
    private function sourceExportAllowed(
        ReportDefinition $definition,
        ReportOperation $operation,
        ?string $exportFormat,
        Closure $permissionGranted,
    ): bool {
        if ($exportFormat !== null) {
            if (! in_array($exportFormat, $definition->formats, true)) {
                return false;
            }

            $permission = match ($exportFormat) {
                'xlsx' => 'act_reports.export.excel',
                'pdf' => 'act_reports.export.pdf',
                default => null,
            };

            return $permission !== null
                && in_array($permission, $definition->permissionPolicy->exportPermissions, true)
                && $permissionGranted($permission);
        }

        if (in_array($operation, [ReportOperation::EXPORT, ReportOperation::DOWNLOAD], true)) {
            return false;
        }

        foreach ($definition->permissionPolicy->exportPermissions as $permission) {
            if ($permissionGranted($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param Closure(string): bool $permissionGranted */
    private function allGranted(array $permissions, Closure $permissionGranted): bool
    {
        foreach ($permissions as $permission) {
            if (! is_string($permission) || ! $permissionGranted($permission)) {
                return false;
            }
        }

        return true;
    }

    private function denied(): ReportVisibility
    {
        return new ReportVisibility(false, false, false, false, false, false, false);
    }
}
