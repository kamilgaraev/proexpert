<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\WaveOne;

use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceBuiltinPublishedReport;
use App\BusinessModules\Features\ScheduleManagement\Reporting\BaselineScheduleVarianceCandidateContract;
use PHPUnit\Framework\TestCase;

final class BaselineScheduleVariancePublishedContractTest extends TestCase
{
    public function test_published_contract_keeps_scope_server_owned(): void
    {
        $contract = new BaselineScheduleVarianceCandidateContract;
        $report = new BaselineScheduleVarianceBuiltinPublishedReport($contract);
        $definition = $report->definition()->payload();

        self::assertSame(
            ['as_of', 'statuses', 'critical'],
            array_column($definition->filters, 'id'),
        );
        self::assertNotContains('organization_id', array_column($definition->filters, 'id'));
        self::assertNotContains('project_ids', array_column($definition->filters, 'id'));
        self::assertSame(['schedule.view'], $definition->permissionPolicy->viewPermissions);
        self::assertSame(['schedule.reports.export'], $definition->permissionPolicy->exportPermissions);
        self::assertSame(['csv', 'xlsx'], $definition->formats);
    }

    public function test_runtime_fingerprints_match_owner_code(): void
    {
        $contract = new BaselineScheduleVarianceCandidateContract;

        $contract->assertRuntimeMatches();
        self::assertSame(64, strlen(BaselineScheduleVarianceCandidateContract::FORMULA_HASH));
        self::assertSame(64, strlen(BaselineScheduleVarianceCandidateContract::SOURCE_HASH));
    }
}
