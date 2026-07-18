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

        $this->createQuantityFactor();
        $this->createVersionedExpectedPrice();
        $this->useVersionedPriceForNewItems();
        $this->validateEachPricingFormulaWithItsOwnVersion();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasVersionedPrices = DB::scalar(<<<'SQL'
SELECT EXISTS (
  SELECT 1 FROM public.estimate_generation_package_items
  WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='norm_measurement:v2'
)
SQL);
        if ((bool) $hasVersionedPrices) {
            throw new RuntimeException('estimate_generation.norm_quantity_formula_rollback_blocked');
        }

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/public\.eg_expected_package_item_price_closed_v2\(p_item_id\)/',
            'public.eg_expected_package_item_price_closed(p_item_id)',
            'estimate_generation.norm_quantity_finalize_rollback_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF\s+current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='norm_measurement:v2'\s+THEN\s+expected:=public\.eg_expected_package_item_price_closed_v2\(NEW\.id\);\s+ELSE\s+expected:=public\.eg_expected_package_item_price_closed\(NEW\.id\);\s+END IF;/i",
            'expected:=public.eg_expected_package_item_price_closed(NEW.id);',
            'estimate_generation.norm_quantity_validator_rollback_contract_changed',
        );
        DB::unprepared($validator);

        DB::unprepared(<<<'SQL'
DROP FUNCTION public.eg_expected_package_item_price_closed_v2(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v2(bigint);
DROP FUNCTION public.eg_norm_quantity_factor(text,text);
SQL);
    }

    private function createQuantityFactor(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_norm_quantity_factor(p_work_unit text, p_norm_unit text) RETURNS numeric
LANGUAGE plpgsql IMMUTABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
DECLARE raw_norm text; compact_norm text; multiplier_text text; norm_multiplier numeric:=1; work_multiplier numeric:=1; norm_base text;
BEGIN
  raw_norm:=lower(trim(COALESCE(p_norm_unit,'')));
  multiplier_text:=substring(raw_norm FROM '^[[:space:]]*([0-9]+([.,][0-9]+)?)');
  IF multiplier_text IS NOT NULL THEN
    norm_multiplier:=replace(multiplier_text,',','.')::numeric;
    raw_norm:=regexp_replace(raw_norm,'^[[:space:]]*[0-9]+([.,][0-9]+)?[[:space:]]*','','');
  END IF;
  compact_norm:=replace(replace(replace(raw_norm,chr(178),'2'),chr(179),'3'),'^','');
  compact_norm:=regexp_replace(compact_norm,'[[:space:].,-]+','','g');

  IF compact_norm ~ ('^('||U&'\043c2'||'|m2|sqm|'||U&'\043a\0432\043c'||')') THEN norm_base:='m2';
  ELSIF compact_norm ~ ('^('||U&'\043c3'||'|m3|cbm|'||U&'\043a\0443\0431\043c'||')') THEN norm_base:='m3';
  ELSIF compact_norm IN (U&'\043c',U&'\043f\043e\0433\043c',U&'\043c\043f','m','lm','rm','linm','linearmeter')
    OR compact_norm ~ ('^'||U&'\043c\0435\0442\0440') THEN norm_base:='m';
  ELSIF compact_norm IN (U&'\0448\0442','pcs','piece','pieces',U&'\0435\0434')
    OR compact_norm ~ ('^('||U&'\0448\0442\0443\043a'||'|'||U&'\0435\0434\0438\043d\0438\0446'||'|'||U&'\043a\043e\043c\043f\043b'||'|'||U&'\0442\043e\0447\043a'||')') THEN norm_base:='pcs';
  ELSIF compact_norm IN (U&'\043a\0433','kg')
    OR compact_norm ~ ('^'||U&'\043a\0438\043b\043e\0433\0440\0430\043c\043c') THEN norm_base:='kg';
  ELSIF compact_norm IN (U&'\0442','t')
    OR compact_norm ~ ('^'||U&'\0442\043e\043d\043d') THEN norm_base:='kg'; norm_multiplier:=norm_multiplier*1000;
  ELSIF compact_norm IN (U&'\0447','h','hour')
    OR compact_norm ~ ('^'||U&'\0447\0430\0441') THEN norm_base:='h';
  ELSE RAISE EXCEPTION 'estimate_generation.norm_quantity_unit_mismatch'; END IF;

  IF p_work_unit = 'm2' THEN work_multiplier:=1;
  ELSIF p_work_unit = 'm3' THEN work_multiplier:=1;
  ELSIF p_work_unit = 'm' THEN work_multiplier:=1;
  ELSIF p_work_unit = 'pcs' THEN work_multiplier:=1;
  ELSIF p_work_unit = 'kg' THEN work_multiplier:=1;
  ELSIF p_work_unit = 'h' THEN work_multiplier:=1;
  ELSIF p_work_unit = 't' THEN work_multiplier:=1000;
  ELSE RAISE EXCEPTION 'estimate_generation.norm_quantity_unit_mismatch'; END IF;
  IF (p_work_unit = 't' AND norm_base <> 'kg') OR (p_work_unit <> 't' AND p_work_unit <> norm_base)
    OR norm_multiplier <= 0 THEN RAISE EXCEPTION 'estimate_generation.norm_quantity_unit_mismatch'; END IF;

  RETURN work_multiplier/norm_multiplier;
END; $$;
REVOKE ALL ON FUNCTION public.eg_norm_quantity_factor(text,text) FROM PUBLIC;
SQL);
    }

    private function createVersionedExpectedPrice(): void
    {
        $definition = $this->definition('public.eg_expected_package_item_price(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+public\.eg_expected_package_item_price\(/i',
            'CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v2(',
            'estimate_generation.norm_quantity_function_name_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            '/(DECLARE\s+item\s+record;\s*evidence\s+record;)/i',
            '${1} norm_unit text; norm_quantity_factor numeric;',
            'estimate_generation.norm_quantity_declaration_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/(THEN\s+RAISE\s+EXCEPTION\s+'estimate_generation\.quantity_evidence_mismatch';\s+END IF;)/i",
            "\${1}\n  SELECT unit INTO norm_unit FROM public.estimate_norms WHERE id=item.estimate_norm_id;\n  norm_quantity_factor:=public.eg_norm_quantity_factor(evidence.value->>'unit', norm_unit);",
            'estimate_generation.norm_quantity_evidence_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/nr\.quantity\s*\*\s*\(evidence\.value->>'quantity'\)::numeric\s*\*\s*COALESCE\(c\.factor,\s*1\)\s*\*\s*rp\.base_price/i",
            "nr.quantity*(evidence.value->>'quantity')::numeric*norm_quantity_factor*COALESCE(c.factor,1)*rp.base_price",
            'estimate_generation.norm_quantity_factor_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/canonical:=canonical\|\|'\|'\|\|item\.quantity_evidence_id\|\|':'\|\|item\.quantity_evidence_fingerprint;/i",
            "canonical:=canonical||'|norm_measurement:v2:'||(evidence.value->>'unit')||':'||norm_unit||':'||norm_quantity_factor::text||'|'||item.quantity_evidence_id||':'||item.quantity_evidence_fingerprint;",
            'estimate_generation.norm_quantity_canonical_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/'norm_quantity',\s*nr\.quantity::text,/i",
            "'norm_quantity',nr.quantity::text,'norm_measurement_unit',norm_unit,'work_to_norm_factor',norm_quantity_factor::text,",
            'estimate_generation.norm_quantity_resource_evidence_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/jsonb_build_object\('work_cost',\s*'0\.00',/i",
            "jsonb_build_object('work_cost','0.00','pricing_formula_version','norm_measurement:v2',",
            'estimate_generation.norm_quantity_snapshot_contract_changed',
        );
        DB::unprepared($definition);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v2(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v2(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v2(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v2(bigint) FROM PUBLIC;
SQL);
    }

    private function useVersionedPriceForNewItems(): void
    {
        $definition = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/public\.eg_expected_package_item_price_closed\(p_item_id\)/',
            'public.eg_expected_package_item_price_closed_v2(p_item_id)',
            'estimate_generation.norm_quantity_finalize_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function validateEachPricingFormulaWithItsOwnVersion(): void
    {
        $definition = $this->definition('public.eg_package_item_price_validate()');
        $definition = $this->replaceOnce(
            $definition,
            '/expected:=public\.eg_expected_package_item_price_closed\(NEW\.id\);/',
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='norm_measurement:v2' THEN\n    expected:=public.eg_expected_package_item_price_closed_v2(NEW.id);\n  ELSE\n    expected:=public.eg_expected_package_item_price_closed(NEW.id);\n  END IF;",
            'estimate_generation.norm_quantity_validator_contract_changed',
        );
        DB::unprepared($definition);
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }

        return $definition;
    }

    private function replaceOnce(string $source, string $pattern, string $replacement, string $error): string
    {
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException($error);
        }

        return $updated;
    }
};
