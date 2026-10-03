<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Infrastructure\Access\LaravelReportModuleEntitlement;
use App\Services\Entitlements\OrganizationEntitlementService;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class LaravelReportModuleEntitlementReadScopeTest extends TestCase
{
    public function test_module_read_is_shared_inside_one_decision_and_reloaded_on_the_next(): void
    {
        $entitlements = $this->createMock(OrganizationEntitlementService::class);
        $entitlements->expects(self::exactly(2))->method('getEffectiveModuleSlugs')->with(7)
            ->willReturnOnConsecutiveCalls(['reports', 'act-reporting'], ['reports']);
        $authorizer = new ReportDefinitionModuleAuthorizer(new LaravelReportModuleEntitlement($entitlements));
        $reports = (new ReportDefinitionBuilder)->sourceModule('reports')->payload();
        $acts = (new ReportDefinitionBuilder)->sourceModule('act-reporting')->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)->payload();
        $decision = $authorizer->decision(7);
        self::assertTrue($decision->allows(7, $reports));
        self::assertTrue($decision->allows(7, $acts));
        self::assertTrue($decision->allows(7, $reports));
        $fresh = $authorizer->decision(7);
        self::assertTrue($fresh->allows(7, $reports));
        self::assertFalse($fresh->allows(7, $acts));
        self::assertFalse($fresh->allows(8, $reports));
    }

    public function test_custom_entitlement_keeps_its_checks_and_fresh_decisions(): void
    {
        $entitlements = $this->createMock(ReportModuleEntitlement::class);
        $entitlements->expects(self::exactly(2))->method('organizationHasModule')->with(7, 'reports')
            ->willReturnOnConsecutiveCalls(true, false);
        $authorizer = new ReportDefinitionModuleAuthorizer($entitlements);
        $definition = (new ReportDefinitionBuilder)->payload();
        $decision = $authorizer->decision(7);
        self::assertTrue($decision->allows(7, $definition));
        self::assertTrue($decision->allows(7, $definition));
        self::assertFalse($authorizer->decision(7)->allows(7, $definition));
    }

    public function test_entitlement_failure_closes_access(): void
    {
        $entitlements = $this->createMock(OrganizationEntitlementService::class);
        $entitlements->expects(self::once())->method('getEffectiveModuleSlugs')->with(7)
            ->willThrowException(new \RuntimeException('unavailable'));
        $decision = (new ReportDefinitionModuleAuthorizer(new LaravelReportModuleEntitlement($entitlements)))->decision(7);
        self::assertFalse($decision->allows(7, (new ReportDefinitionBuilder)->payload()));
    }
}
