<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class AssistantIfcInsertAutovacuumMigrationTest extends TestCase
{
    public function test_migration_preserves_data_and_other_options_and_restores_inherited_defaults(): void
    {
        $this->prepareTable();
        $migration = $this->migration();
        foreach ([1, 2] as $attempt) {
            $migration->up();
            self::assertSame(['autovacuum_vacuum_insert_scale_factor' => '0.05', 'autovacuum_vacuum_scale_factor' => '0.02', 'fillfactor' => '80'], $this->relationOptions());
            self::assertSame('7s', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
        }
        foreach ([1, 2] as $attempt) {
            $migration->down();
            self::assertSame(['autovacuum_vacuum_scale_factor' => '0.02', 'fillfactor' => '80'], $this->relationOptions());
            self::assertSame('7s', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
        }
        self::assertSame('unchanged', DB::table('design_ifc_model_elements')->where('id', 1)->value('payload'));
        self::assertSame(1, DB::table('design_ifc_model_elements')->count());
    }

    public function test_migration_refuses_an_unexpected_override_and_restores_the_callers_timeout(): void
    {
        $this->prepareTable();
        DB::statement('ALTER TABLE design_ifc_model_elements SET (autovacuum_vacuum_insert_scale_factor = 0.07)');
        foreach (['up', 'down'] as $direction) {
            try {
                $this->migration()->{$direction}();
                self::fail('An operator override must not be replaced silently.');
            } catch (RuntimeException $exception) {
                self::assertSame('Unexpected IFC element insert autovacuum override', $exception->getMessage());
            }
            self::assertSame(['autovacuum_vacuum_insert_scale_factor' => '0.07', 'autovacuum_vacuum_scale_factor' => '0.02', 'fillfactor' => '80'], $this->relationOptions());
            self::assertSame('7s', DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value);
            self::assertSame('unchanged', DB::table('design_ifc_model_elements')->where('id', 1)->value('payload'));
        }
    }

    private function prepareTable(): void
    {
        $schema = 'ifc_insert_maintenance_'.bin2hex(random_bytes(6));
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        DB::selectOne("SELECT set_config('search_path', ?, true)", [$schema.', public']);
        DB::selectOne("SELECT set_config('lock_timeout', '7s', true)");
        DB::statement('CREATE TABLE design_ifc_model_elements (id bigint PRIMARY KEY, payload text) WITH (fillfactor = 80, autovacuum_vacuum_scale_factor = 0.02)');
        DB::table('design_ifc_model_elements')->insert(['id' => 1, 'payload' => 'unchanged']);
    }

    private function relationOptions(): array
    {
        $options = [];
        foreach (DB::select("SELECT split_part(setting.value, '=', 1) AS name, split_part(setting.value, '=', 2) AS value "
            .'FROM pg_class AS relation CROSS JOIN LATERAL unnest(relation.reloptions) AS setting(value) '
            .'WHERE relation.oid = CAST(? AS regclass)', ['design_ifc_model_elements']) as $option) {
            $options[$option->name] = $option->value;
        }
        ksort($options);

        return $options;
    }

    private function migration(): Migration
    {
        return require base_path('app/BusinessModules/Features/DesignManagement/migrations/2026_10_16_160000_tune_ifc_element_insert_autovacuum.php');
    }
}
