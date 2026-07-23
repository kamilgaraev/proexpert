<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialAbstractResourceConversionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'estimate_generation_pinned_abstract_resource_conversions';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->createConversionRules();
        $this->seedConversionRules();
        $this->linkPriceInputs();
        $this->createImmutableUsageGuard();
        $this->createVersionedExpectedPrice();
        $this->useVersionedPriceForNewItems();
        $this->validateEachPricingFormulaWithItsOwnVersion();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ((bool) DB::scalar(<<<'SQL'
SELECT EXISTS (
  SELECT 1
  FROM public.estimate_generation_package_items
  WHERE price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v8'
)
SQL)) {
            throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_rollback_blocked');
        }

        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v8\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v7(p_item_id); END IF;',
            'estimate_generation.pinned_abstract_resource_conversion_finalize_rollback_contract_changed',
        );
        DB::unprepared($finalize);

        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v8' THEN\s+expected:=public\.eg_expected_package_item_price_closed_v8\(NEW\.id\);\s+ELSIF/i",
            'IF',
            'estimate_generation.pinned_abstract_resource_conversion_validator_rollback_contract_changed',
        );
        DB::unprepared($validator);
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_pinned_abstract_resource_conversion_usage_immutable ON public.estimate_generation_pinned_abstract_resource_conversions;
DROP FUNCTION IF EXISTS public.eg_pinned_abstract_resource_conversion_usage_guard();
DROP FUNCTION public.eg_expected_package_item_price_closed_v8(bigint);
DROP FUNCTION public.eg_expected_package_item_price_v8(bigint);
SQL);

        Schema::table('estimate_generation_package_item_price_inputs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_abstract_resource_conversion_id');
        });
        Schema::dropIfExists(self::TABLE);
    }

    private function createConversionRules(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('rule_key', 160);
            $table->unsignedInteger('version');
            $table->string('norm_code', 64);
            $table->string('abstract_group_code', 64);
            $table->string('candidate_group_code', 64);
            $table->string('from_unit', 50);
            $table->string('to_unit', 50);
            $table->decimal('quantity_factor', 30, 12);
            $table->decimal('monetary_factor', 30, 12);
            $table->string('assumption', 255);
            $table->char('fingerprint', 64);
            $table->timestampsTz();
            $table->unique(['rule_key', 'version'], 'eg_pinned_abstract_conversion_version_uq');
            $table->unique('fingerprint', 'eg_pinned_abstract_conversion_fingerprint_uq');
            $table->index(['norm_code', 'abstract_group_code'], 'eg_pinned_abstract_conversion_lookup_idx');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE public.estimate_generation_pinned_abstract_resource_conversions
  ADD CONSTRAINT eg_pinned_abstract_conversion_version_positive_ck CHECK (version > 0),
  ADD CONSTRAINT eg_pinned_abstract_conversion_quantity_factor_positive_ck CHECK (quantity_factor > 0),
  ADD CONSTRAINT eg_pinned_abstract_conversion_monetary_factor_positive_ck CHECK (monetary_factor > 0);
SQL);
    }

    private function seedConversionRules(): void
    {
        foreach ($this->catalogRules() as $rule) {
            DB::table(self::TABLE)->insertOrIgnore($rule);

            $persisted = DB::table(self::TABLE)
                ->where('rule_key', $rule['rule_key'])
                ->where('version', $rule['version'])
                ->first(['fingerprint']);

            if ($persisted === null || $persisted->fingerprint !== $rule['fingerprint']) {
                throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_seed_conflict');
            }
        }
    }

    private function linkPriceInputs(): void
    {
        Schema::table('estimate_generation_package_item_price_inputs', function (Blueprint $table): void {
            $table->foreignId('pinned_abstract_resource_conversion_id')
                ->nullable()
                ->constrained(self::TABLE)
                ->restrictOnDelete();
        });
    }

    private function createImmutableUsageGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.eg_pinned_abstract_resource_conversion_usage_guard() RETURNS trigger
LANGUAGE plpgsql SET search_path=pg_catalog,public AS $$
BEGIN
  RAISE EXCEPTION 'estimate_generation.pinned_abstract_resource_conversion_is_append_only';
END; $$;
CREATE TRIGGER eg_pinned_abstract_resource_conversion_usage_immutable
BEFORE UPDATE OR DELETE ON public.estimate_generation_pinned_abstract_resource_conversions
FOR EACH ROW EXECUTE FUNCTION public.eg_pinned_abstract_resource_conversion_usage_guard();
REVOKE ALL ON FUNCTION public.eg_pinned_abstract_resource_conversion_usage_guard() FROM PUBLIC;
SQL);
    }

    private function createVersionedExpectedPrice(): void
    {
        $definition = $this->definition('public.eg_expected_package_item_price_v7(bigint)');
        $definition = $this->replaceOnce(
            $definition,
            '/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+public\.eg_expected_package_item_price_v7\(/i',
            'CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_v8(',
            'estimate_generation.pinned_abstract_resource_conversion_function_name_contract_changed',
        );
        $definition = str_replace('|semantic_project_resource:v7:', '|semantic_project_resource:v8:', $definition, $canonicalCount);
        $definition = str_replace("'semantic_project_resource:v7'", "'semantic_project_resource:v8'", $definition, $formulaVersionCount);
        if ($canonicalCount !== 1 || $formulaVersionCount !== 1) {
            throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_version_contract_changed');
        }

        $definition = $this->replaceAll(
            $definition,
            '/LEFT\s+JOIN\s+public\.estimate_generation_unit_conversions\s+c\s+ON\s+c\.id\s*=\s*i\.unit_conversion_id/i',
            "LEFT JOIN public.estimate_generation_unit_conversions c ON c.id=i.unit_conversion_id\n  LEFT JOIN public.estimate_generation_pinned_abstract_resource_conversions arc ON arc.id=i.pinned_abstract_resource_conversion_id",
            2,
            'estimate_generation.pinned_abstract_resource_conversion_join_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            '/OR\s+\(rp\.unit\s+IS\s+DISTINCT\s+FROM\s+nr\.unit\s+AND\s+\(c\.id\s+IS\s+NULL\s+OR\s+c\.from_unit\s+IS\s+DISTINCT\s+FROM\s+nr\.unit\s+OR\s+c\.to_unit\s+IS\s+DISTINCT\s+FROM\s+rp\.unit\s+OR\s+c\.factor\s*<=\s*0\)\)\s+OR\s+\(rp\.unit\s+IS\s+NOT\s+DISTINCT\s+FROM\s+nr\.unit\s+AND\s+c\.id\s+IS\s+NOT\s+NULL\)/i',
            <<<'SQL'
OR (
        i.pinned_abstract_resource_conversion_id IS NULL
        AND (
          (rp.unit IS DISTINCT FROM nr.unit AND (c.id IS NULL OR c.from_unit IS DISTINCT FROM nr.unit OR c.to_unit IS DISTINCT FROM rp.unit OR c.factor<=0))
          OR (rp.unit IS NOT DISTINCT FROM nr.unit AND c.id IS NOT NULL)
        )
      )
      OR (
        i.pinned_abstract_resource_conversion_id IS NOT NULL
        AND (
          arc.id IS NULL
          OR LOWER(COALESCE(nr.raw_payload->>'source_tag', '')) <> 'abstractresource'
          OR nr.resource_code IS DISTINCT FROM arc.abstract_group_code
          OR nr.unit IS DISTINCT FROM arc.from_unit
          OR rp.unit IS DISTINCT FROM arc.to_unit
          OR rp.resource_code !~ ('^'||replace(arc.candidate_group_code, '.', '\.')||'-[0-9]{4}$')
          OR arc.quantity_factor<=0
          OR arc.monetary_factor<=0
          OR c.id IS NOT NULL
        )
      )
SQL,
            'estimate_generation.pinned_abstract_resource_conversion_validation_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            '/COALESCE\(c\.factor,\s*1\)\s*\*\s*rp\.base_price/i',
            'COALESCE(arc.monetary_factor,c.factor,1)*rp.base_price',
            'estimate_generation.pinned_abstract_resource_conversion_money_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/COALESCE\(c\.id::text,'identity'\)\s*\|\|\s*':'\s*\|\|\s*COALESCE\(c\.factor::text,'1'\)/i",
            "COALESCE(c.id::text,'identity')||':'||COALESCE(c.factor::text,'1')||':'||COALESCE(arc.id::text,'identity')||':'||COALESCE(arc.fingerprint,'identity')||':'||COALESCE(arc.quantity_factor::text,'1')||':'||COALESCE(arc.monetary_factor::text,'1')",
            'estimate_generation.pinned_abstract_resource_conversion_canonical_contract_changed',
        );
        $definition = $this->replaceOnce(
            $definition,
            "/'unit_conversion_id',\s*c\.id,\s*'conversion_factor',\s*COALESCE\(c\.factor,\s*1\)::text/i",
            "'unit_conversion_id',c.id,'pinned_abstract_resource_conversion_id',arc.id,'pinned_abstract_resource_conversion_key',arc.rule_key,'pinned_abstract_resource_conversion_version',arc.version,'conversion_factor',COALESCE(arc.monetary_factor,c.factor,1)::text,'quantity_factor',COALESCE(arc.quantity_factor,1)::text",
            'estimate_generation.pinned_abstract_resource_conversion_resource_snapshot_contract_changed',
        );
        DB::unprepared($definition);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION public.eg_expected_package_item_price_closed_v8(p_item_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,public AS $$
SELECT jsonb_set(public.eg_expected_package_item_price_v8(p_item_id),'{snapshot,coefficients,provenance}',public.eg_pricing_provenance(p_item_id),true);
$$;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_v8(bigint) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.eg_expected_package_item_price_closed_v8(bigint) FROM PUBLIC;
SQL);
    }

    private function useVersionedPriceForNewItems(): void
    {
        $finalize = $this->definition('public.eg_finalize_package_item_price(bigint)');
        $finalize = $this->replaceOnce(
            $finalize,
            '/ELSE expected:=public\.eg_expected_package_item_price_closed_v7\(p_item_id\); END IF;/',
            'ELSE expected:=public.eg_expected_package_item_price_closed_v8(p_item_id); END IF;',
            'estimate_generation.pinned_abstract_resource_conversion_finalize_contract_changed',
        );
        DB::unprepared($finalize);
    }

    private function validateEachPricingFormulaWithItsOwnVersion(): void
    {
        $validator = $this->definition('public.eg_package_item_price_validate()');
        $validator = $this->replaceOnce(
            $validator,
            "/IF current_item\.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v7' THEN/i",
            "IF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v8' THEN\n"
            ."    expected:=public.eg_expected_package_item_price_closed_v8(NEW.id);\n"
            ."  ELSIF current_item.price_snapshot->'coefficients'->>'pricing_formula_version'='semantic_project_resource:v7' THEN",
            'estimate_generation.pinned_abstract_resource_conversion_validator_contract_changed',
        );
        DB::unprepared($validator);
    }

    /** @return list<array<string, int|string>> */
    private function catalogRules(): array
    {
        $catalog = new ResidentialAbstractResourceConversionCatalog();
        $keys = [
            ['06-23-003-05', '08.4.01.02'],
            ['07-01-021-01', '05.1.03.09'],
            ['12-01-013-07', '12.2.05.02'],
            ['15-01-019-05', '06.2.05.04'],
            ['17-01-003-01', '18.2.06.08'],
            ['17-01-001-14', '18.2.02.08'],
        ];

        $now = now();
        $rules = [];
        foreach ($keys as [$normCode, $groupCode]) {
            $conversion = $catalog->find($normCode, $groupCode);
            if ($conversion === null) {
                throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_catalog_rule_missing');
            }

            $version = 1;
            $ruleKey = $normCode.'|'.$groupCode;
            $monetaryFactor = (string) $conversion['factor'];
            $quantityFactor = $this->inverseFactor($monetaryFactor);
            $fingerprint = hash('sha256', implode('|', [
                $ruleKey,
                (string) $version,
                (string) $conversion['candidate_group_code'],
                (string) $conversion['from_unit'],
                (string) $conversion['to_unit'],
                $quantityFactor,
                $monetaryFactor,
                (string) $conversion['assumption'],
            ]));
            $rules[] = [
                'rule_key' => $ruleKey,
                'version' => $version,
                'norm_code' => $normCode,
                'abstract_group_code' => $groupCode,
                'candidate_group_code' => (string) $conversion['candidate_group_code'],
                'from_unit' => (string) $conversion['from_unit'],
                'to_unit' => (string) $conversion['to_unit'],
                'quantity_factor' => $quantityFactor,
                'monetary_factor' => $monetaryFactor,
                'assumption' => (string) $conversion['assumption'],
                'fingerprint' => $fingerprint,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rules;
    }

    private function definition(string $signature): string
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('{$signature}'::regprocedure)");
        if (! is_string($definition) || $definition === '') {
            throw new RuntimeException('estimate_generation.pricing_function_missing');
        }

        return $definition;
    }

    private function inverseFactor(string $factor): string
    {
        if (! function_exists('bccomp') || ! function_exists('bcdiv')) {
            throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_decimal_math_unavailable');
        }

        if (bccomp($factor, '0', 12) !== 1) {
            throw new RuntimeException('estimate_generation.pinned_abstract_resource_conversion_factor_invalid');
        }

        return rtrim(rtrim(bcdiv('1', $factor, 12), '0'), '.');
    }

    private function replaceOnce(string $source, string $pattern, string $replacement, string $error): string
    {
        $updated = preg_replace($pattern, $replacement, $source, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new RuntimeException($error);
        }

        return $updated;
    }

    private function replaceAll(string $source, string $pattern, string $replacement, int $expectedCount, string $error): string
    {
        $updated = preg_replace($pattern, $replacement, $source, -1, $count);
        if (! is_string($updated) || $count !== $expectedCount) {
            throw new RuntimeException($error);
        }

        return $updated;
    }
};
