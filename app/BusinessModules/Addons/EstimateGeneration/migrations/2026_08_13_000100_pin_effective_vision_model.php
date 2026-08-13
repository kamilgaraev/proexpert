<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Addons\EstimateGeneration\Settings\VisionModelPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('estimate_generation_vision_model_pin_requires_postgresql');
        }

        Schema::table('estimate_generation_ai_operations', function (Blueprint $table): void {
            $table->string('vision_model', 192)->nullable();
        });
        DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_ai_operations
ADD CONSTRAINT eg_ai_operation_vision_model_ck
CHECK (
  vision_model IS NULL OR vision_model IN (
    'openai/gpt-5.6-luna',
    'gemini/gemini-3.1-flash',
    'gemini/gemini-3.5-flash'
  )
)
SQL);

        DB::statement('DROP FUNCTION eg_pin_ai_operation_settings(uuid,bigint,bigint)');
        DB::unprepared(<<<'SQL'
CREATE FUNCTION eg_pin_ai_operation_settings(
  p_correlation uuid,
  p_organization bigint,
  p_session bigint,
  p_vision_override text,
  p_vision_fallback text
)
RETURNS TABLE(global_snapshot_id bigint, effective_snapshot_id bigint, vision_model text)
LANGUAGE plpgsql AS $$
DECLARE
  v_existing estimate_generation_ai_operations%ROWTYPE;
  v_global bigint;
  v_effective bigint;
  v_model text;
  v_fingerprint text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('estimate-generation-operation:' || p_correlation::text, 0));
  SELECT * INTO v_existing FROM estimate_generation_ai_operations WHERE correlation_id = p_correlation;
  IF FOUND THEN
    IF v_existing.organization_id <> p_organization OR v_existing.session_id <> p_session THEN
      RAISE EXCEPTION 'estimate_generation_ai_operation_identity_conflict';
    END IF;
    SELECT COALESCE(
      v_existing.vision_model,
      snapshots.snapshot #>> '{models,vision}'
    ) INTO STRICT v_model
    FROM estimate_generation_setting_snapshots snapshots
    WHERE snapshots.id = v_existing.effective_setting_snapshot_id;
    RETURN QUERY SELECT v_existing.global_setting_snapshot_id, v_existing.effective_setting_snapshot_id, v_model;
    RETURN;
  END IF;
  IF NOT EXISTS (
    SELECT 1 FROM estimate_generation_sessions
    WHERE id = p_session AND organization_id = p_organization
  ) THEN
    RAISE EXCEPTION 'estimate_generation_ai_operation_scope_invalid';
  END IF;
  SELECT id INTO STRICT v_global
  FROM estimate_generation_setting_snapshots
  WHERE scope = 'global' AND organization_id IS NULL AND snapshot->>'schema_version' = '2'
  ORDER BY version DESC LIMIT 1;
  SELECT id INTO v_effective
  FROM estimate_generation_setting_snapshots
  WHERE scope = 'organization' AND organization_id = p_organization AND snapshot->>'schema_version' = '2'
  ORDER BY version DESC LIMIT 1;
  v_effective := COALESCE(v_effective, v_global);
  SELECT COALESCE(
    NULLIF(btrim(p_vision_override), ''),
    snapshots.snapshot #>> '{models,vision}',
    NULLIF(btrim(p_vision_fallback), '')
  ) INTO STRICT v_model
  FROM estimate_generation_setting_snapshots snapshots
  WHERE snapshots.id = v_effective;
  IF v_model NOT IN (
    'openai/gpt-5.6-luna',
    'gemini/gemini-3.1-flash',
    'gemini/gemini-3.5-flash'
  ) THEN
    RAISE EXCEPTION 'estimate_generation_vision_model_unsupported';
  END IF;
  v_fingerprint := 'sha256:' || encode(pg_catalog.sha256(pg_catalog.convert_to(
    p_correlation::text || '|' || p_organization::text || '|' || p_session::text || '|'
    || v_global::text || '|' || v_effective::text || '|' || v_model,
    'UTF8'
  )), 'hex');
  INSERT INTO estimate_generation_ai_operations
    (correlation_id, organization_id, session_id, global_setting_snapshot_id,
     effective_setting_snapshot_id, vision_model, immutable_fingerprint, created_at)
  VALUES
    (p_correlation, p_organization, p_session, v_global,
     v_effective, v_model, v_fingerprint, now());
  RETURN QUERY SELECT v_global, v_effective, v_model;
END;
$$;
SQL);

        $this->appendLunaSettingsSnapshots();
    }

    public function down(): void
    {
        throw new RuntimeException('estimate_generation_vision_model_pin_is_forward_only');
    }

    private function appendLunaSettingsSnapshots(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE estimate_generation_setting_snapshots IN SHARE ROW EXCLUSIVE MODE');
            $rows = DB::select(<<<'SQL'
SELECT DISTINCT ON (scope, organization_id)
  id, scope, organization_id, version, snapshot, daily_budget, monthly_budget,
  currency, created_by_system_admin_id
FROM estimate_generation_setting_snapshots
WHERE snapshot->>'schema_version' = '2'
ORDER BY scope, organization_id NULLS FIRST, version DESC
SQL);

            foreach ($rows as $row) {
                $snapshot = is_string($row->snapshot)
                    ? json_decode($row->snapshot, true, 64, JSON_THROW_ON_ERROR)
                    : $row->snapshot;
                if (! is_array($snapshot) || ! is_array($snapshot['models'] ?? null)
                    || ($snapshot['models']['vision'] ?? null) === VisionModelPolicy::LUNA) {
                    continue;
                }
                $oldModels = $snapshot['models'];
                $snapshot['models']['vision'] = VisionModelPolicy::LUNA;
                $nextVersion = (int) DB::table('estimate_generation_setting_snapshots')
                    ->where('scope', $row->scope)
                    ->where(function ($query) use ($row): void {
                        $row->organization_id === null
                            ? $query->whereNull('organization_id')
                            : $query->where('organization_id', $row->organization_id);
                    })
                    ->max('version') + 1;
                $snapshotHash = SettingsSnapshotHash::calculate($snapshot);
                $snapshotId = (int) DB::table('estimate_generation_setting_snapshots')->insertGetId([
                    'scope' => $row->scope,
                    'organization_id' => $row->organization_id,
                    'version' => $nextVersion,
                    'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'snapshot_hash' => $snapshotHash,
                    'daily_budget' => $row->daily_budget,
                    'monthly_budget' => $row->monthly_budget,
                    'currency' => $row->currency,
                    'created_by_system_admin_id' => $row->created_by_system_admin_id,
                    'created_at' => now(),
                ]);
                DB::table('estimate_generation_setting_snapshot_hashes')->insert([
                    'setting_snapshot_id' => $snapshotId,
                    'algorithm' => 'jcs-sha256-v1',
                    'snapshot_hash' => $snapshotHash,
                    'created_at' => now(),
                ]);
                $fingerprint = 'sha256:'.hash('sha256', json_encode([
                    'release' => '2026-08-13-luna-cutover',
                    'scope' => $row->scope,
                    'organization_id' => $row->organization_id,
                    'version' => $nextVersion,
                    'models' => $snapshot['models'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
                DB::table('estimate_generation_setting_audits')->insert([
                    'setting_snapshot_id' => $snapshotId,
                    'scope' => $row->scope,
                    'organization_id' => $row->organization_id,
                    'actor_system_admin_id' => $row->created_by_system_admin_id,
                    'key' => 'models',
                    'old_value' => json_encode($oldModels, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'new_value' => json_encode($snapshot['models'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                    'command_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);
            }
        }, 1);
    }
};
