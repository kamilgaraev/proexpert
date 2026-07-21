<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $definition = DB::scalar("SELECT pg_get_functiondef('public.eg_expected_package_item_price(bigint)'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.price_input_validator_function_missing');
        }

        $replacement = <<<'SQL'
CASE WHEN nr.resource_code ~ '^[0-9]{2}\.[0-9]\.[0-9]{2}\.[0-9]{2}$' THEN
        rp.resource_code !~ ('^'||replace(nr.resource_code, '.', '\\.')||'-[0-9]{4}$')
        AND NOT EXISTS (
          SELECT 1 FROM jsonb_array_elements(
            COALESCE((item.resources::jsonb)->'materials', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'labor', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'machinery', '[]'::jsonb)
            || COALESCE((item.resources::jsonb)->'other', '[]'::jsonb)
          ) AS persisted_resource(value)
          WHERE persisted_resource.value->'normative_ref'->>'norm_resource_id' = nr.id::text
            AND persisted_resource.value->'normative_ref'->>'price_id' = rp.id::text
            AND persisted_resource.value->'normative_ref'->'project_resource_selection'->>'selected_resource_code' = rp.resource_code
            AND (persisted_resource.value->'normative_ref'->'project_resource_selection'->>'candidates_count') ~ '^[1-9][0-9]*$'
        )
      ELSE rp.resource_code IS DISTINCT FROM nr.resource_code END
SQL;
        $updated = preg_replace(
            "/CASE WHEN LOWER\\(COALESCE\\(nr\\.raw_payload->>'source_tag', ''\\)\\) = 'abstractresource'\\s+THEN.*?ELSE rp\\.resource_code IS DISTINCT FROM nr\\.resource_code END/is",
            $replacement,
            $definition,
            1,
            $count,
        );
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException('estimate_generation.price_input_validator_contract_changed');
        }

        DB::unprepared($updated);
    }

    public function down(): void {}
};
