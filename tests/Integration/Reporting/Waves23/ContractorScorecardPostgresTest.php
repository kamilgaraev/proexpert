<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorScorecardPolicyWriter;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

final class ContractorScorecardPostgresTest extends TestCase
{
    public function test_concurrent_policy_first_writers_are_serialized_by_the_unique_version_identity(): void
    {
        $this->requirePostgresProcessHarness();
        $organizationId = (int) Organization::factory()->create()->id;
        $effectiveFrom = CarbonImmutable::now('UTC')->addMinute()->toISOString();
        $components = [[
            'code' => 'quality_cycle',
            'unit_code' => 'days',
            'source_report_code' => 'quality_defect_flow',
            'source_formula_version' => 'quality-defect-flow.v1',
            'source_schema_version' => 'quality-defect-flow.v1',
            'source_metric' => 'cycle_days',
        ]];
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'contractor-policy-race-'.bin2hex(random_bytes(6)),
        );
        $children = [];

        try {
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use (
                    $organizationId,
                    $effectiveFrom,
                    $components,
                ): array {
                    $policy = app(ContractorScorecardPolicyWriter::class)->append(
                        $organizationId,
                        $components,
                        ['period' => 'quarter'],
                        '0.75000000',
                        3,
                        CarbonImmutable::parse($effectiveFrom),
                    );

                    return ['id' => (int) $policy->id, 'version' => (string) $policy->version];
                });
            }
            foreach ([1, 2] as $worker) {
                $harness->release($worker);
            }
            $harness->waitForChildren($children);
            $children = [];
            $results = [$harness->result(1), $harness->result(2)];

            self::assertSame($results[0]['id'], $results[1]['id']);
            self::assertSame('contractor-scorecard.v1', $results[0]['version']);
            self::assertSame(1, DB::table('contractor_scorecard_policy_versions')
                ->where('organization_id', $organizationId)
                ->count());
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    private function requirePostgresProcessHarness(): void
    {
        if (
            DB::connection()->getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork')
            || ! function_exists('posix_kill')
        ) {
            self::markTestSkipped('Requires PostgreSQL with pcntl/posix.');
        }
    }
}
