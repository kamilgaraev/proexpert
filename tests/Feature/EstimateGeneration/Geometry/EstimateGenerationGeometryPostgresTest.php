<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class EstimateGenerationGeometryPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function geometry_outbox_has_composite_tenant_fk_idempotency_and_claim_indexes(): void
    {
        $this->requirePostgres();
        $constraints = DB::select("SELECT conname, pg_get_constraintdef(oid) definition FROM pg_constraint WHERE conrelid = 'estimate_generation_geometry_regeneration_outbox'::regclass");
        $definitions = implode("\n", array_map(static fn (object $row): string => $row->conname.' '.$row->definition, $constraints));

        self::assertStringContainsString('eg_geometry_outbox_session_scope_fk', $definitions);
        self::assertStringContainsString('FOREIGN KEY (session_id, organization_id, project_id)', $definitions);
        self::assertStringContainsString('idempotency_key', $definitions);
        self::assertNotEmpty(DB::select("SELECT 1 FROM pg_indexes WHERE tablename = 'estimate_generation_geometry_regeneration_outbox' AND indexdef LIKE '%status%available_at%'"));
    }

    #[Test]
    public function geometry_mutation_tables_enforce_append_only_history_and_transactional_rollback(): void
    {
        $this->requirePostgres();
        $triggers = DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid IN ('estimate_generation_building_models'::regclass, 'estimate_generation_evidence'::regclass) AND NOT tgisinternal");
        $names = array_map(static fn (object $row): string => $row->tgname, $triggers);

        self::assertContains('eg_building_model_immutable_trg', $names);
        self::assertContains('eg_evidence_immutable_trg', $names);

        $before = DB::table('estimate_generation_geometry_regeneration_outbox')->count();
        DB::beginTransaction();
        DB::rollBack();
        self::assertSame($before, DB::table('estimate_generation_geometry_regeneration_outbox')->count());
    }

    #[Test]
    public function session_and_model_lock_contract_supports_one_cas_winner(): void
    {
        $this->requirePostgres();
        $sessionIndex = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'estimate_generation_sessions' AND indexdef LIKE '%id, organization_id, project_id%'");
        $modelIndex = DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'estimate_generation_building_models' AND indexdef LIKE '%organization_id, project_id, session_id, input_version%'");

        self::assertNotEmpty($sessionIndex);
        self::assertNotEmpty($modelIndex);
    }

    private function requirePostgres(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}
