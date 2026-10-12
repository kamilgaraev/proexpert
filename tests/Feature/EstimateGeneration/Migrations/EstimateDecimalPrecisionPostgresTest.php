<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class EstimateDecimalPrecisionPostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_precision_upgrade_preserves_materialized_definition_indexes_access_and_comment_and_replays(): void
    {
        DB::transaction(function (): void {
            $this->prepareProjection();
            DB::statement('CREATE UNIQUE INDEX precision_usage_quantity_idx ON mv_normative_rates_usage(quantity)');
            DB::statement('GRANT SELECT ON mv_normative_rates_usage TO PUBLIC');
            DB::statement("COMMENT ON MATERIALIZED VIEW mv_normative_rates_usage IS 'precision projection'");
            $before = $this->projection();

            $this->migration()->up();
            $after = $this->projection();
            self::assertSame($before->definition, $after->definition);
            self::assertSame($before->owner_name, $after->owner_name);
            self::assertSame($before->comment, $after->comment);
            self::assertSame($before->public_readable, $after->public_readable);
            self::assertTrue($after->populated);
            self::assertSame(1, DB::table('pg_indexes')->where('indexname', 'precision_usage_quantity_idx')->count());
            $column = DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')
                ->where('table_name', 'estimate_items')->where('column_name', 'quantity')->first();
            self::assertSame(24, (int) $column->numeric_precision);
            self::assertSame(8, (int) $column->numeric_scale);
            self::assertSame(0, DB::table('mv_normative_rates_usage')->count());
            $this->migration()->up();
            self::assertSame($after->oid, $this->projection()->oid);
            DB::statement('DROP MATERIALIZED VIEW mv_normative_rates_usage');
        });
    }

    public function test_unknown_dependant_prevents_upgrade_without_losing_either_view_or_column_precision(): void
    {
        DB::transaction(function (): void {
            $this->prepareProjection();
            DB::statement('CREATE VIEW precision_unknown_dependant AS SELECT quantity FROM mv_normative_rates_usage');
            $before = $this->projection();
            try {
                $this->migration()->up();
                self::fail('An unknown dependant was silently removed.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('depend', $exception->getMessage());
            }
            self::assertSame($before->oid, $this->projection()->oid);
            self::assertSame(0, DB::table('precision_unknown_dependant')->count());
            $column = DB::table('information_schema.columns')->whereRaw('table_schema = current_schema()')
                ->where('table_name', 'estimate_items')->where('column_name', 'quantity')->first();
            self::assertSame(20, (int) $column->numeric_precision);
            DB::statement('DROP VIEW precision_unknown_dependant');
            $this->migration()->up();
            DB::statement('DROP MATERIALIZED VIEW mv_normative_rates_usage');
        });
    }

    public function test_price_only_upgrade_preserves_an_existing_unpopulated_projection(): void
    {
        DB::transaction(function (): void {
            DB::statement('ALTER TABLE estimate_items ALTER COLUMN unit_price TYPE numeric(20,4)');
            DB::statement('CREATE MATERIALIZED VIEW mv_normative_rates_usage AS SELECT quantity FROM estimate_items WITH NO DATA');
            $before = $this->projection();
            $this->migration()->up();
            self::assertSame($before->oid, $this->projection()->oid);
            self::assertFalse($this->projection()->populated);
            DB::statement('DROP MATERIALIZED VIEW mv_normative_rates_usage');
        });
    }

    private function prepareProjection(): void
    {
        DB::statement('ALTER TABLE estimate_items ALTER COLUMN quantity TYPE numeric(20,8)');
        DB::statement('CREATE MATERIALIZED VIEW mv_normative_rates_usage AS SELECT quantity FROM estimate_items');
    }

    private function migration(): \Illuminate\Database\Migrations\Migration
    {
        return require database_path('migrations/2026_10_12_001400_preserve_estimate_quantity_and_unit_price_precision.php');
    }

    private function projection(): object
    {
        return DB::selectOne(<<<'SQL'
            SELECT c.oid, pg_get_viewdef(c.oid, true) AS definition, pg_get_userbyid(c.relowner) AS owner_name,
                   c.relispopulated AS populated, obj_description(c.oid, 'pg_class') AS comment,
                   EXISTS (SELECT 1 FROM aclexplode(c.relacl) acl WHERE acl.grantee = 0 AND acl.privilege_type = 'SELECT') AS public_readable
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relname = 'mv_normative_rates_usage' AND n.nspname = current_schema()
            SQL);
    }
}
