<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->configure(true);
    }

    public function down(): void
    {
        $this->configure(false);
    }

    private function configure(bool $enabled): void
    {
        DB::transaction(function () use ($enabled): void {
            $previousTimeout = DB::selectOne("SELECT current_setting('lock_timeout') AS value")->value;
            DB::selectOne("SELECT set_config('lock_timeout', '2s', true)");
            DB::statement('LOCK TABLE design_ifc_model_elements IN SHARE UPDATE EXCLUSIVE MODE');
            $current = DB::selectOne("SELECT split_part(setting.value, '=', 2) AS scale_factor FROM pg_class AS relation "
                ."CROSS JOIN LATERAL unnest(relation.reloptions) AS setting(value) "
                ."WHERE relation.oid = CAST(? AS regclass) AND split_part(setting.value, '=', 1) = 'autovacuum_vacuum_scale_factor'",
                ['design_ifc_model_elements']);
            if ($current !== null && (float) $current->scale_factor !== 0.02) {
                throw new RuntimeException('Unexpected IFC element autovacuum override');
            }
            DB::statement('ALTER TABLE design_ifc_model_elements '.($enabled
                ? 'SET (autovacuum_vacuum_scale_factor = 0.02)'
                : 'RESET (autovacuum_vacuum_scale_factor)'));
            DB::selectOne("SELECT set_config('lock_timeout', ?, true)", [$previousTimeout]);
        });
    }
};
