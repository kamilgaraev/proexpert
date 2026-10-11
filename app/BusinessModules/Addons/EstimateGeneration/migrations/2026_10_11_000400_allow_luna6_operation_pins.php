<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definition = DB::scalar("SELECT pg_get_functiondef('eg_pin_ai_operation_settings(uuid,bigint,bigint,text,text)'::regprocedure)");
        if (! is_string($definition) || ! str_contains($definition, "'openai/gpt-5.6-luna',")) {
            throw new RuntimeException('luna6_operation_pin_contract_mismatch');
        }
        DB::statement('ALTER TABLE estimate_generation_ai_operations DROP CONSTRAINT eg_ai_operation_vision_model_ck');
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_generation_ai_operations ADD CONSTRAINT eg_ai_operation_vision_model_ck
            CHECK (vision_model IS NULL OR vision_model IN ('openai/gpt-6-luna','openai/gpt-5.6-luna','gemini/gemini-3.1-flash','gemini/gemini-3.5-flash'))
            SQL);
        if (! str_contains($definition, "'openai/gpt-6-luna',")) {
            DB::unprepared(str_replace("'openai/gpt-5.6-luna',", "'openai/gpt-6-luna',\n    'openai/gpt-5.6-luna',", $definition));
        }
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE estimate_generation_setting_snapshots IN SHARE ROW EXCLUSIVE MODE');
            $rows = DB::select("SELECT DISTINCT ON (scope, organization_id) * FROM estimate_generation_setting_snapshots WHERE snapshot->>'schema_version'='2' ORDER BY scope, organization_id NULLS FIRST, version DESC");
            foreach ($rows as $row) {
                $snapshot = is_array($row->snapshot) ? $row->snapshot : json_decode($row->snapshot, true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($snapshot) || ! is_array($snapshot['models'] ?? null)) {
                    throw new RuntimeException('luna6_settings_snapshot_invalid');
                }
                if (count(array_filter($snapshot['models'], static fn ($model): bool => $model !== 'openai/gpt-6-luna')) === 0) {
                    continue;
                }
                $oldModels = $snapshot['models'];
                $snapshot['models'] = array_fill_keys(['vision', 'classification', 'normative_matching'], 'openai/gpt-6-luna');
                $hash = SettingsSnapshotHash::calculate($snapshot);
                $id = DB::table('estimate_generation_setting_snapshots')->insertGetId([
                    'scope' => $row->scope, 'organization_id' => $row->organization_id, 'version' => (int) DB::table('estimate_generation_setting_snapshots')->where('scope', $row->scope)
                        ->when($row->organization_id === null, static fn ($query) => $query->whereNull('organization_id'), static fn ($query) => $query->where('organization_id', $row->organization_id))->max('version') + 1,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_hash' => $hash,
                    'daily_budget' => $row->daily_budget, 'monthly_budget' => $row->monthly_budget, 'currency' => $row->currency,
                    'created_by_system_admin_id' => $row->created_by_system_admin_id, 'created_at' => now(),
                ]);
                DB::table('estimate_generation_setting_snapshot_hashes')->insert([
                    'setting_snapshot_id' => $id, 'algorithm' => 'jcs-sha256-v1', 'snapshot_hash' => $hash, 'created_at' => now(),
                ]);
                DB::table('estimate_generation_setting_audits')->insert([
                    'setting_snapshot_id' => $id, 'scope' => $row->scope, 'organization_id' => $row->organization_id,
                    'actor_system_admin_id' => $row->created_by_system_admin_id, 'key' => 'models',
                    'old_value' => json_encode($oldModels, JSON_THROW_ON_ERROR), 'new_value' => json_encode($snapshot['models'], JSON_THROW_ON_ERROR),
                    'command_fingerprint' => 'sha256:'.hash('sha256', '2026-10-11-luna6-cutover:'.$id.':'.$hash), 'created_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('luna6_operation_pins_are_history_preserving_forward_only');
    }
};
