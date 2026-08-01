<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Access;

use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionModuleAuthorizer;
use App\BusinessModules\Core\Reporting\Application\Access\ReportDefinitionVisibilityResolver;
use App\BusinessModules\Core\Reporting\Application\Contracts\Access\ReportModuleEntitlement;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportCoreAccessMode;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportOperation;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportDefinitionModuleAccessDecisionTest extends TestCase
{
    public function test_entitlement_is_memoized_by_module_only_inside_one_decision(): void
    {
        $entitlements = new RecordingReportModuleEntitlement([
            'act-reporting' => false,
            'reports' => true,
        ]);
        $resolver = new ReportDefinitionVisibilityResolver(
            new ReportDefinitionModuleAuthorizer($entitlements),
        );
        $sourceFirst = (new ReportDefinitionBuilder)
            ->code('source_first')
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();
        $sourceSecond = (new ReportDefinitionBuilder)
            ->code('source_second')
            ->sourceModule('act-reporting')
            ->coreAccessMode(ReportCoreAccessMode::SOURCE_MODULE_REPORT)
            ->formats(['xlsx'])
            ->permissionPolicy(new ReportPermissionPolicy(
                ['act_reports.view'],
                ['act_reports.export.excel'],
                [],
                [],
            ))
            ->payload();
        $generic = (new ReportDefinitionBuilder)
            ->code('generic_report')
            ->sourceModule('reports')
            ->payload();
        $permissionGranted = static fn (string $permission): bool => true;
        $decision = $resolver->moduleAccessDecision(7);

        self::assertFalse($resolver->resolve(7, $sourceFirst, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertFalse($resolver->resolve(7, $sourceSecond, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertTrue($resolver->resolve(7, $generic, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertTrue($resolver->resolve(7, $generic, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertSame(['7:act-reporting' => 1, '7:reports' => 1], $entitlements->calls);

        $entitlements->allowed = ['act-reporting' => true, 'reports' => false];
        self::assertFalse($resolver->resolve(7, $sourceFirst, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertTrue($resolver->resolve(7, $generic, ReportOperation::VIEW, null, $permissionGranted, $decision)->canView);
        self::assertSame(['7:act-reporting' => 1, '7:reports' => 1], $entitlements->calls);

        $freshDecision = $resolver->moduleAccessDecision(7);
        self::assertTrue($resolver->resolve(7, $sourceFirst, ReportOperation::VIEW, null, $permissionGranted, $freshDecision)->canView);
        self::assertFalse($resolver->resolve(7, $generic, ReportOperation::VIEW, null, $permissionGranted, $freshDecision)->canView);
        self::assertSame(['7:act-reporting' => 2, '7:reports' => 2], $entitlements->calls);

        self::assertFalse($resolver->resolve(8, $sourceFirst, ReportOperation::VIEW, null, $permissionGranted, $freshDecision)->canView);
        self::assertSame(['7:act-reporting' => 2, '7:reports' => 2], $entitlements->calls);
    }
}

final class RecordingReportModuleEntitlement implements ReportModuleEntitlement
{
    /** @var array<string, bool> */
    public array $allowed;

    /** @var array<string, int> */
    public array $calls = [];

    /** @param array<string, bool> $allowed */
    public function __construct(array $allowed)
    {
        $this->allowed = $allowed;
    }

    public function organizationHasModule(int $organizationId, string $moduleSlug): bool
    {
        $key = $organizationId.':'.$moduleSlug;
        $this->calls[$key] = ($this->calls[$key] ?? 0) + 1;

        return $this->allowed[$moduleSlug] ?? false;
    }
}
