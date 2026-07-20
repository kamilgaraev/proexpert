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

        $this->replaceFunction(
            'public.eg_expected_project_material_price_id_v4(bigint)',
            $this->priceIdCanonicalizations(),
            'estimate_generation.project_material_price_id_canonicalization_contract_changed',
        );
        $this->replaceFunction(
            'public.eg_expected_package_item_price_v4(bigint)',
            $this->packagePriceCanonicalizations(),
            'estimate_generation.project_material_package_price_canonicalization_contract_changed',
        );
        $this->revokePublicExecution();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasCanonicalizedV4 = DB::scalar(<<<'SQL'
SELECT EXISTS (SELECT 1 FROM public.estimate_generation_package_items
WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='supplementary_project_material:v4')
SQL);
        if ((bool) $hasCanonicalizedV4) {
            throw new RuntimeException('estimate_generation.project_material_canonicalization_rollback_blocked');
        }

        $this->replaceFunction(
            'public.eg_expected_project_material_price_id_v4(bigint)',
            $this->reverse($this->priceIdCanonicalizations()),
            'estimate_generation.project_material_price_id_canonicalization_rollback_contract_changed',
        );
        $this->replaceFunction(
            'public.eg_expected_package_item_price_v4(bigint)',
            $this->reverse($this->packagePriceCanonicalizations()),
            'estimate_generation.project_material_package_price_canonicalization_rollback_contract_changed',
        );
        $this->revokePublicExecution();
    }

    /** @return array<string, string> */
    private function priceIdCanonicalizations(): array
    {
        return [
            'rp.unit=x.source_unit AND rp.base_price>0' => 'trim(rp.unit)=trim(x.source_unit) AND rp.base_price>0',
            "CASE dv.source_type WHEN 'fsbc' THEN 0 WHEN 'fsnb_2022' THEN 1 ELSE 2 END" => "CASE trim(dv.source_type) WHEN 'fsbc' THEN 0 WHEN 'fsnb_2022' THEN 1 ELSE 2 END",
            "dv.status='parsed' AND dv.source_type IN ('fsbc','fsnb_2022')" => "dv.status='parsed' AND trim(dv.source_type) IN ('fsbc','fsnb_2022')",
            'rp.resource_code=x.preferred_resource_code' => 'trim(rp.resource_code)=trim(x.preferred_resource_code)',
            "rp.resource_code ~ ('^'||replace(x.fallback_group_code,'.','\\.')||'-[0-9]{4}$')" => "trim(rp.resource_code) ~ ('^'||replace(trim(x.fallback_group_code),'.','\\.')||'-[0-9]{4}$')",
            "rp.resource_code ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}-[0-9]{4}$'" => "trim(rp.resource_code) ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}-[0-9]{4}$'",
            'lower(rp.resource_name)' => 'lower(trim(rp.resource_name))',
            'ORDER BY base_price,resource_code,dataset_source_priority,id DESC' => 'ORDER BY base_price,trim(resource_code),dataset_source_priority,id DESC',
        ];
    }

    /** @return array<string, string> */
    private function packagePriceCanonicalizations(): array
    {
        return [
            'rp.unit IS DISTINCT FROM r.source_unit' => 'trim(rp.unit) IS DISTINCT FROM trim(r.source_unit)',
            "dv.status='parsed' AND dv.source_type IN ('fsbc','fsnb_2022')" => "dv.status='parsed' AND trim(dv.source_type) IN ('fsbc','fsnb_2022')",
            "i.selection->>'price_source'=CASE dv.source_type WHEN 'fsbc' THEN 'fsbc_base' ELSE 'fsnb_base' END" => "i.selection->>'price_source'=CASE trim(dv.source_type) WHEN 'fsbc' THEN 'fsbc_base' ELSE 'fsnb_base' END",
            "i.selection->>'price_source_version'=rv.version_key" => "i.selection->>'price_source_version'=trim(rv.version_key)",
            "i.selection->>'price_source_version'=dv.version_key" => "i.selection->>'price_source_version'=trim(dv.version_key)",
            'rp.resource_code=r.preferred_resource_code' => 'trim(rp.resource_code)=trim(r.preferred_resource_code)',
            "rp.resource_code ~ ('^'||replace(r.fallback_group_code,'.','\\.')||'-[0-9]{4}$')" => "trim(rp.resource_code) ~ ('^'||replace(trim(r.fallback_group_code),'.','\\.')||'-[0-9]{4}$')",
            "rp.resource_code ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}-[0-9]{4}$'" => "trim(rp.resource_code) ~ '^[0-9]{2}\\.[0-9]\\.[0-9]{2}\\.[0-9]{2}-[0-9]{4}$'",
            'lower(rp.resource_name)' => 'lower(trim(rp.resource_name))',
            "rp.id||':'||rp.resource_code||':'||rp.unit||':'||rp.base_price" => "rp.id||':'||trim(rp.resource_code)||':'||trim(rp.unit)||':'||rp.base_price",
            "'price_unit',rp.unit" => "'price_unit',trim(rp.unit)",
        ];
    }

    /** @param array<string, string> $replacements */
    private function replaceFunction(string $signature, array $replacements, string $error): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition)
            || stripos($definition, 'SECURITY DEFINER') === false
            || stripos($definition, 'search_path') === false) {
            throw new RuntimeException($error);
        }

        foreach ($replacements as $search => $replacement) {
            if (substr_count($definition, $search) < 1) {
                throw new RuntimeException($error);
            }
            $definition = str_replace($search, $replacement, $definition);
        }

        DB::unprepared($definition);
    }

    /** @param array<string, string> $replacements @return array<string, string> */
    private function reverse(array $replacements): array
    {
        $reversed = [];
        foreach ($replacements as $search => $replacement) {
            $reversed[$replacement] = $search;
        }

        return $reversed;
    }

    private function revokePublicExecution(): void
    {
        DB::unprepared(<<<'SQL'
REVOKE ALL ON FUNCTION public.eg_expected_project_material_price_id_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v4(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v4(bigint) FROM PUBLIC;
SQL);
    }
};
