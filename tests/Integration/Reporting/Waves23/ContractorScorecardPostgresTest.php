<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use Illuminate\Support\Facades\DB;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;
use Throwable;

final class ContractorScorecardPostgresTest extends TestCase
{
    public function test_concurrent_policy_first_writers_are_serialized_by_the_unique_version_identity(): void
    {
        $this->requirePostgresProcessHarness();
        $organizationId = random_int(700_000_000, 799_999_999);
        $version = 'contractor-scorecard.race.'.bin2hex(random_bytes(6));
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'contractor-policy-race-'.bin2hex(random_bytes(6)),
        );
        $children = [];

        try {
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($organizationId, $version): array {
                    try {
                        DB::table('contractor_scorecard_policy_versions')->insert([
                            'organization_id' => $organizationId,
                            'version' => $version,
                            'components' => '[]',
                            'cohort_rules' => '{"period":"quarter"}',
                            'minimum_coverage' => '1',
                            'minimum_sample_size' => 1,
                            'source_hash' => str_repeat('a', 64),
                            'effective_from' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        return ['inserted' => true];
                    } catch (Throwable $exception) {
                        return ['inserted' => false, 'sql_state' => (string) $exception->getCode()];
                    }
                });
                $harness->release($worker);
            }
            $harness->waitForChildren($children);
            $children = [];
            $results = [$harness->result(1), $harness->result(2)];

            self::assertSame(1, count(array_filter($results, static fn (array $row): bool => $row['inserted'])));
            self::assertSame(1, DB::table('contractor_scorecard_policy_versions')
                ->where('organization_id', $organizationId)
                ->where('version', $version)
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
