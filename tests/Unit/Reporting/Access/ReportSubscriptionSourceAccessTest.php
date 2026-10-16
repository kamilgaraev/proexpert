<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportSubscriptionSourceAccessTest extends TestCase
{
    #[DataProvider('managementCases')]
    public function test_management_needs_published_capability_source_access_workspace_and_both_permissions(
        bool $supports, bool $workspace, array $permissions, bool $expected,
    ): void {
        $entitlements = $this->createStub(ReportModuleEntitlement::class);
        $entitlements->method('organizationHasModule')->willReturnCallback(
            static fn (int $organizationId, string $module): bool => $organizationId === 1 && ($module !== 'reports' || $workspace),
        );
        $definition = (new ReportDefinitionBuilder)->sourceModule('contract-management')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)->supportsSubscriptions($supports)
            ->permissionPolicy(new ReportPermissionPolicy(['source.view'], ['source.export'], [], []))->payload();
        $visibility = (new ReportDefinitionVisibilityResolver(new ReportDefinitionModuleAuthorizer($entitlements)))
            ->resolve(1, $definition, ReportOperation::MANAGE, null, static fn (string $permission): bool => in_array($permission, $permissions, true));

        self::assertSame($expected, $visibility->canManage);
        self::assertSame(in_array('source.view', $permissions, true), $visibility->canView);
        self::assertFalse($visibility->canExport);
    }

    public static function managementCases(): array
    {
        $all = ['source.view', 'reports.view', 'reports.manage'];

        return [[true, true, $all, true], [false, true, $all, false], [true, false, $all, false],
            [true, true, ['source.view', 'reports.view'], false], [true, true, ['source.view', 'reports.manage'], false],
            [true, true, ['reports.view', 'reports.manage'], false]];
    }
}
