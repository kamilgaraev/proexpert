<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Migrations;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contract')]
final class ObsoleteReviewContoursCleanupPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function fresh_contract_schema_contains_only_canonical_estimator_contours(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('most_ai_estimator_contract', DB::getDatabaseName());

        foreach ([
            'estimate_generation_sheet_analysis_operations',
            'estimate_generation_geometry_regeneration_outbox',
            'estimate_generation_geometry_confirmations',
            'estimate_generation_building_model_evidence',
            'estimate_generation_building_models',
        ] as $obsolete) {
            self::assertNull(DB::selectOne('SELECT to_regclass(?) AS relation', [$obsolete])->relation);
        }

        foreach ([
            'estimate_generation_evidence',
            'estimate_generation_project_model_fact_evidence',
            'estimate_generation_project_model_derived_quantities',
            'estimate_generation_ai_role_runs',
            'estimate_change_proposals',
        ] as $canonical) {
            self::assertSame($canonical, DB::selectOne('SELECT to_regclass(?)::text AS relation', [$canonical])->relation);
        }

        self::assertNull(DB::selectOne(
            "SELECT to_regprocedure('eg_project_model_evidence_binding_guard()') AS routine",
        )->routine);
    }

    #[Test]
    public function definition_mismatch_fails_before_any_destructive_statement(): void
    {
        $schema = 'most_cleanup_mismatch_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA "'.$schema.'"');
        DB::unprepared('SET search_path TO "'.$schema.'"');
        DB::statement('CREATE TABLE estimate_generation_sheet_analysis_operations (operation_id uuid PRIMARY KEY)');

        try {
            $migration = require app_path(
                'BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000900_remove_obsolete_estimate_generation_review_contours.php',
            );
            try {
                $migration->up();
                self::fail('Tampered obsolete schema was accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'obsolete_estimate_generation_schema_mismatch:estimate_generation_sheet_analysis_operations',
                    $exception->getMessage(),
                );
            }
            self::assertSame(
                'estimate_generation_sheet_analysis_operations',
                DB::selectOne("SELECT to_regclass('estimate_generation_sheet_analysis_operations')::text AS relation")->relation,
            );
        } finally {
            DB::unprepared('SET search_path TO public');
            if (preg_match('/^most_cleanup_mismatch_[a-f0-9]{16}$/D', $schema) === 1) {
                DB::unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
            }
        }
    }
}
