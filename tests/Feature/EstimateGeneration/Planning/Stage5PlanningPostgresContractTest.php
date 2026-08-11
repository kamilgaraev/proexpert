<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Planning;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class Stage5PlanningPostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function unpublished_stage_five_migrations_are_retry_safe_and_indexes_are_ready(): void
    {
        $this->requireEnvironment();
        foreach (['000700_create_technology_planning_projections', '000710_create_completeness_planning_projections'] as $suffix) {
            $file = glob(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/*_'.$suffix.'.php');
            self::assertCount(1, $file);
            (require $file[0])->up();
            (require $file[0])->up();
        }
        foreach ([
            'eg_tech_plan_current_idx',
            'eg_tech_plan_one_current_uq',
            'eg_completeness_current_idx',
            'eg_completeness_one_current_uq',
        ] as $index) {
            $state = DB::selectOne(<<<'SQL'
SELECT index_state.indisvalid, index_state.indisready, pg_get_indexdef(index_state.indexrelid) AS definition
FROM pg_index AS index_state
JOIN pg_class AS index_class ON index_class.oid = index_state.indexrelid
JOIN pg_namespace AS index_schema ON index_schema.oid = index_class.relnamespace
WHERE index_schema.nspname = current_schema() AND index_class.relname = ?
SQL, [$index]);
            self::assertNotNull($state, $index.' is missing.');
            self::assertTrue((bool) $state->indisvalid, $index.' is invalid.');
            self::assertTrue((bool) $state->indisready, $index.' is not ready.');
            self::assertStringContainsString('estimate_generation_', (string) $state->definition);
        }
    }

    #[Test]
    public function partial_unique_indexes_reject_two_current_projections_in_the_same_scope(): void
    {
        $this->requireEnvironment();
        DB::beginTransaction();
        try {
            $base = [
                'organization_id' => 910001,
                'project_id' => 920001,
                'session_id' => 930001,
                'source_version' => 'sha256:'.str_repeat('a', 64),
                'input_fingerprint' => str_repeat('b', 64),
                'catalog_version' => 'contract-v1',
                'catalog_hash' => str_repeat('c', 64),
                'result_fingerprint' => str_repeat('d', 64),
                'limitations' => '[]',
                'is_current' => true,
                'created_at' => now(),
            ];
            DB::table('estimate_generation_technology_planning_runs')->insert($base);
            $duplicate = $base;
            $duplicate['input_fingerprint'] = str_repeat('e', 64);
            $duplicate['result_fingerprint'] = str_repeat('f', 64);

            $this->expectException(QueryException::class);
            DB::table('estimate_generation_technology_planning_runs')->insert($duplicate);
        } finally {
            DB::rollBack();
        }
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}
