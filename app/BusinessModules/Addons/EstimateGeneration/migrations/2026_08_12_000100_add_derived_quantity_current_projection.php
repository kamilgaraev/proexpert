<?php

declare(strict_types=1);

use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration implements ForwardOnlyMigration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('estimate_generation_project_model_derived_quantities', function (Blueprint $table): void {
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'logical_key')) {
                $table->string('logical_key', 192)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'exact_identity')) {
                $table->char('exact_identity', 64)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'formula_identity')) {
                $table->string('formula_identity', 120)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'formula_version')) {
                $table->string('formula_version', 80)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'rounding_boundary')) {
                $table->string('rounding_boundary', 64)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'unit_compatibility')) {
                $table->string('unit_compatibility', 32)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'snapshot_identity')) {
                $table->jsonb('snapshot_identity')->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'technology_decision_id')) {
                $table->string('technology_decision_id', 192)->nullable();
            }
            if (! Schema::hasColumn('estimate_generation_project_model_derived_quantities', 'identity_status')) {
                $table->string('identity_status', 24)->nullable();
            }
        });

        $this->installIdentityBackfillGuard();
        try {
            do {
                $ids = DB::table('estimate_generation_project_model_derived_quantities')
                    ->whereNull('identity_status')
                    ->orderBy('id')
                    ->limit(500)
                    ->pluck('id')
                    ->all();
                if ($ids !== []) {
                    DB::table('estimate_generation_project_model_derived_quantities')
                        ->whereIn('id', $ids)
                        ->update(['identity_status' => 'legacy_unverifiable']);
                }
            } while ($ids !== []);
        } finally {
            $this->restoreAppendGuard();
        }

        if (! $this->constraintExists('estimate_generation_project_model_derived_quantities', 'eg_pm_derived_exact_contract_ck')) {
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_derived_quantities
    ADD CONSTRAINT eg_pm_derived_exact_contract_ck CHECK (
        identity_status IN ('legacy_unverifiable', 'exact')
        AND (identity_status <> 'exact' OR (
            logical_key IS NOT NULL
            AND exact_identity ~ '^[a-f0-9]{64}$'
            AND stable_key = 'quantityv:' || exact_identity
            AND formula_identity IS NOT NULL
            AND formula_version IS NOT NULL
            AND rounding_boundary IN ('formula_result', 'irrational_operation_then_formula_result')
            AND unit_compatibility IN ('exact', 'canonical_conversion')
            AND jsonb_typeof(snapshot_identity) = 'object'
        ))
    ) NOT VALID
SQL);
        }
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS eg_pm_derived_exact_scope_uq
ON estimate_generation_project_model_derived_quantities
    (id, organization_id, project_id, session_id, source_version, logical_key, exact_identity)
SQL);
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_pm_derived_history_scope_idx
ON estimate_generation_project_model_derived_quantities
    (organization_id, project_id, session_id, source_version, logical_key, id)
WHERE identity_status = 'exact'
SQL);

        if (! Schema::hasTable('estimate_generation_project_model_derived_quantity_projections')) {
            Schema::create('estimate_generation_project_model_derived_quantity_projections', function (Blueprint $table): void {
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('project_id');
                $table->unsignedBigInteger('session_id');
                $table->string('source_version', 71);
                $table->string('logical_key', 192);
                $table->unsignedBigInteger('derived_quantity_id');
                $table->char('exact_identity', 64);
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->primary(
                    ['organization_id', 'project_id', 'session_id', 'source_version', 'logical_key'],
                    'eg_pm_derived_current_pk',
                );
            });
        }
        if (! $this->constraintExists('estimate_generation_project_model_derived_quantity_projections', 'eg_pm_derived_current_pk')) {
            $primary = DB::table('pg_constraint')
                ->where('conrelid', DB::raw("'estimate_generation_project_model_derived_quantity_projections'::regclass"))
                ->where('contype', 'p')
                ->value('conname');
            if (is_string($primary) && preg_match('/^[a-z0-9_]+$/D', $primary) === 1) {
                DB::statement("ALTER TABLE estimate_generation_project_model_derived_quantity_projections RENAME CONSTRAINT {$primary} TO eg_pm_derived_current_pk");
            }
        }
        if (! $this->constraintExists('estimate_generation_project_model_derived_quantity_projections', 'eg_pm_derived_current_exact_ck')) {
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_derived_quantity_projections
    ADD CONSTRAINT eg_pm_derived_current_exact_ck
    CHECK (exact_identity ~ '^[a-f0-9]{64}$')
SQL);
        }
        if (! $this->constraintExists('estimate_generation_project_model_derived_quantity_projections', 'eg_pm_derived_current_history_fk')) {
            DB::statement(<<<'SQL'
ALTER TABLE estimate_generation_project_model_derived_quantity_projections
    ADD CONSTRAINT eg_pm_derived_current_history_fk FOREIGN KEY
    (derived_quantity_id, organization_id, project_id, session_id, source_version, logical_key, exact_identity)
    REFERENCES estimate_generation_project_model_derived_quantities
    (id, organization_id, project_id, session_id, source_version, logical_key, exact_identity)
    ON DELETE RESTRICT NOT VALID
SQL);
        }
    }

    private function constraintExists(string $table, string $name): bool
    {
        return DB::table('pg_constraint')
            ->where('conrelid', DB::raw("'{$table}'::regclass"))
            ->where('conname', $name)
            ->exists();
    }

    private function installIdentityBackfillGuard(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION eg_pm_derived_identity_backfill_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE'
       OR OLD.identity_status IS NOT NULL
       OR NEW.identity_status <> 'legacy_unverifiable'
       OR (to_jsonb(NEW) - 'identity_status') IS DISTINCT FROM (to_jsonb(OLD) - 'identity_status') THEN
        RAISE EXCEPTION 'estimate_generation.project_model_append_only';
    END IF;
    RETURN NEW;
END; $$;
DROP TRIGGER IF EXISTS eg_pm_derived_append_trg ON estimate_generation_project_model_derived_quantities;
CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_pm_derived_identity_backfill_guard();
SQL);
    }

    private function restoreAppendGuard(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_pm_derived_append_trg ON estimate_generation_project_model_derived_quantities;
CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
DROP FUNCTION IF EXISTS eg_pm_derived_identity_backfill_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Current derived quantity projection is forward-only and preserves immutable history.');
    }
};
