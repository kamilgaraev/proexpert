<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Access\ReportingPermissionMatrix;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use App\BusinessModules\Core\Reporting\ReportingModule;
use PHPUnit\Framework\TestCase;

final class ReportingPermissionMatrixContractTest extends TestCase
{
    public function test_declarative_matrix_is_the_single_source_for_core_permissions_and_operation_dependencies(): void
    {
        self::assertSame([
            'reports.view',
            'reports.run',
            'reports.export',
            'reports.download',
            'reports.manage',
            'reports.sensitive',
            'reports.audit',
        ], ReportingPermissionMatrix::corePermissions());
        self::assertSame([
            ReportOperation::VIEW->value => ['reports.view'],
            ReportOperation::RUN->value => ['reports.view', 'reports.run'],
            ReportOperation::EXPORT->value => ['reports.view', 'reports.export'],
            ReportOperation::DOWNLOAD->value => ['reports.view', 'reports.export', 'reports.download'],
            ReportOperation::MANAGE->value => ['reports.view', 'reports.manage'],
            ReportOperation::VIEW_SENSITIVE->value => ['reports.view', 'reports.sensitive'],
            ReportOperation::VIEW_AUDIT->value => ['reports.view', 'reports.audit'],
            ReportOperation::DRILL_DOWN->value => ['reports.view'],
        ], ReportingPermissionMatrix::operationRequirements());
    }

    public function test_access_layers_routes_manifest_and_labels_are_synchronized_with_matrix(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = json_decode((string) file_get_contents($root.'/config/ModuleList/core/reports.json'), true, 512, JSON_THROW_ON_ERROR);
        $labels = require $root.'/lang/ru/permissions.php';
        $routes = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/routes.php');
        $access = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Application/Access/ReportAccessService.php');
        $authorizer = (string) file_get_contents($root.'/app/BusinessModules/Core/Reporting/Infrastructure/Execution/LaravelCurrentReportScopeAuthorizer.php');

        self::assertSame(ReportingModule::class, $manifest['class_name']);
        self::assertSame('App\\BusinessModules\\Core\\Reporting\\ReportingContractsServiceProvider', $manifest['service_provider']);
        self::assertSame(['app/BusinessModules/Core/Reporting/routes.php'], $manifest['routes']);
        self::assertContains('reports.project_readiness.view', $manifest['permissions']);
        self::assertContains('reports.project_readiness.export', $manifest['permissions']);

        foreach (ReportingPermissionMatrix::corePermissions() as $permission) {
            self::assertContains($permission, $manifest['permissions']);
            self::assertIsString($labels['values'][$permission] ?? null);
            self::assertNotSame('', $labels['values'][$permission]);
        }

        self::assertStringContainsString('ReportingPermissionMatrix::requiredFor', $access);
        self::assertStringContainsString('ReportingPermissionMatrix::permissionChecks', $authorizer);
        self::assertStringContainsString('ReportingPermissionMatrix::middleware', $routes);
        self::assertStringNotContainsString('Core\\\\Reports\\\\ReportsModule', json_encode($manifest, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('routes/api/v1/admin/reports.php', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
