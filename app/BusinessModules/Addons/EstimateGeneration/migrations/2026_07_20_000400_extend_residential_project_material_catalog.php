<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CATALOG_VERSION = 'residential_project_material:v5';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::table('estimate_generation_project_material_rules')->insert($this->rules());
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $isUsed = DB::table('estimate_generation_package_item_project_price_inputs as inputs')
            ->join('estimate_generation_project_material_rules as rules', 'rules.id', '=', 'inputs.project_material_rule_id')
            ->where('rules.catalog_version', self::CATALOG_VERSION)
            ->exists();
        if ($isUsed) {
            throw new RuntimeException('estimate_generation.residential_project_material_catalog_rollback_blocked');
        }

        DB::unprepared('DROP TRIGGER IF EXISTS eg_project_material_rule_immutable ON public.estimate_generation_project_material_rules');
        DB::table('estimate_generation_project_material_rules')
            ->where('catalog_version', self::CATALOG_VERSION)
            ->delete();
        DB::unprepared(<<<'SQL'
CREATE TRIGGER eg_project_material_rule_immutable BEFORE UPDATE OR DELETE ON public.estimate_generation_project_material_rules
FOR EACH ROW EXECUTE FUNCTION public.eg_project_material_rule_immutable_guard()
SQL);
    }

    /** @return list<array<string, mixed>> */
    private function rules(): array
    {
        $now = now();

        return array_map(static fn (array $rule): array => [
            'catalog_version' => self::CATALOG_VERSION,
            ...$rule,
            'fallback_name_markers' => json_encode($rule['fallback_name_markers'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'semantic_name_markers' => json_encode($rule['semantic_name_markers'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['work_item_key' => 'electrical.main_cable', 'scenario_assumption_code' => 'residential_feeder_cable_clips', 'assumption_code' => 'residential_main_cable_vvgng_ls_3x6_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0154', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['work_item_key' => 'electrical.power_lines', 'scenario_assumption_code' => 'residential_power_wiring_channels', 'assumption_code' => 'residential_power_cable_vvgng_ls_3x2_5_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0152', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['work_item_key' => 'lighting.lines', 'scenario_assumption_code' => 'residential_lighting_wiring_chases', 'assumption_code' => 'residential_lighting_cable_vvgng_ls_3x1_5_with_waste_5_percent', 'preferred_resource_code' => '21.1.06.09-0151', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => '1000 м', 'source_unit' => '1000 м', 'quantity_per_work_unit' => '0.00105', 'price_factor' => '1'],
            ['work_item_key' => 'electrical.panel', 'scenario_assumption_code' => 'residential_recessed_lighting_panel', 'assumption_code' => 'residential_recessed_distribution_panel_24_modules', 'preferred_resource_code' => '20.4.04.02-0003', 'fallback_group_code' => '20.4.04.02', 'fallback_name_markers' => ['щит'], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['work_item_key' => 'electrical.outlets', 'scenario_assumption_code' => 'residential_recessed_socket', 'assumption_code' => 'residential_recessed_grounded_socket_with_shutter', 'preferred_resource_code' => '20.4.03.06-1036', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['work_item_key' => 'electrical.switches', 'scenario_assumption_code' => 'residential_recessed_single_switch', 'assumption_code' => 'residential_recessed_single_switch', 'preferred_resource_code' => '20.4.01.02-1023', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['work_item_key' => 'lighting.fixtures', 'scenario_assumption_code' => 'residential_ceiling_luminaire', 'assumption_code' => 'residential_led_ceiling_luminaire_18w', 'preferred_resource_code' => '59.1.20.03-0798', 'fallback_group_code' => '59.1.20.03', 'fallback_name_markers' => ['светиль'], 'semantic_name_markers' => ['светиль', 'светодиод'], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
            ['work_item_key' => 'heating.unit', 'scenario_assumption_code' => 'residential_electric_boiler_installation_analog_30kg', 'assumption_code' => 'residential_wall_mounted_single_circuit_electric_boiler_18kw', 'preferred_resource_code' => '89.1.63.01-0079', 'fallback_group_code' => null, 'fallback_name_markers' => [], 'semantic_name_markers' => [], 'material_unit' => 'pcs', 'source_unit' => 'шт', 'quantity_per_work_unit' => '1', 'price_factor' => '1'],
        ]);
    }
};
