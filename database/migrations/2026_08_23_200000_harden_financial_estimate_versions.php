<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_versions', function (Blueprint $table): void {
            $table->string('status', 40)->default('archived');
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->unique(['estimate_id', 'idempotency_key'], 'estimate_versions_idempotency_unique');
            $table->index(['estimate_id', 'status'], 'estimate_versions_status_idx');
        });

        DB::table('estimate_versions')
            ->where('snapshot_type', 'approval')
            ->update(['status' => 'superseded']);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE estimate_versions AS version
                SET status = 'approved'
                FROM (
                    SELECT DISTINCT ON (estimate_id) id
                    FROM estimate_versions
                    WHERE snapshot_type = 'approval'
                    ORDER BY estimate_id, version_number DESC, id DESC
                ) AS latest
                WHERE version.id = latest.id
                SQL);
        } else {
            DB::table('estimate_versions')
                ->whereIn('id', function ($query): void {
                    $query->from('estimate_versions')
                        ->selectRaw('MAX(id)')
                        ->where('snapshot_type', 'approval')
                        ->groupBy('estimate_id');
                })
                ->update(['status' => 'approved']);
        }

        Schema::table('estimates', function (Blueprint $table): void {
            $table->foreignId('current_version_id')
                ->nullable()
                ->constrained('estimate_versions')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE estimates AS estimate
                SET current_version_id = version.id
                FROM estimate_versions AS version
                WHERE version.estimate_id = estimate.id
                    AND version.status = 'approved'
                SQL);
        } else {
            DB::table('estimates')
                ->whereIn('id', function ($query): void {
                    $query->from('estimate_versions')->select('estimate_id')->where('status', 'approved');
                })
                ->orderBy('id')
                ->chunkById(500, static function ($estimates): void {
                    foreach ($estimates as $estimate) {
                        $currentVersionId = DB::table('estimate_versions')
                            ->where('estimate_id', $estimate->id)
                            ->where('status', 'approved')
                            ->value('id');
                        DB::table('estimates')
                            ->where('id', $estimate->id)
                            ->update(['current_version_id' => $currentVersionId]);
                    }
                });
        }

        app(\App\BusinessModules\Features\BudgetEstimates\Services\Versioning\ApprovedEstimateVersionBackfillService::class)
            ->backfill();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX estimate_versions_one_approved_idx
                ON estimate_versions (estimate_id)
                WHERE status = 'approved'
            SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE estimate_versions
                ADD CONSTRAINT estimate_versions_status_check
                CHECK (status IN ('draft', 'in_review', 'approved', 'rejected', 'superseded', 'archived'))
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_sealed_estimate_header()
            RETURNS trigger AS $$
            BEGIN
                IF OLD.current_version_id IS NOT NULL AND OLD.status = 'approved' THEN
                    IF NEW.current_version_id IS DISTINCT FROM OLD.current_version_id
                        AND (to_jsonb(NEW) - ARRAY['current_version_id', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['current_version_id', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.status = 'draft'
                        AND (to_jsonb(NEW) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at'])
                            = (to_jsonb(OLD) - ARRAY['status', 'approved_by_user_id', 'approved_at', 'updated_at']) THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'approved_estimate_is_immutable' USING ERRCODE = '23514';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement(<<<'SQL'
            CREATE TRIGGER estimates_sealed_header_guard
                BEFORE UPDATE OR DELETE ON estimates
                FOR EACH ROW EXECUTE FUNCTION guard_sealed_estimate_header()
            SQL);
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_sealed_estimate_child()
            RETURNS trigger AS $$
            DECLARE
                target_estimate_id bigint;
                target_item_id bigint;
            BEGIN
                IF TG_TABLE_NAME = 'estimate_item_resources' THEN
                    target_item_id := COALESCE(NEW.estimate_item_id, OLD.estimate_item_id);
                    SELECT estimate_id INTO target_estimate_id FROM estimate_items WHERE id = target_item_id;
                ELSE
                    target_estimate_id := COALESCE(NEW.estimate_id, OLD.estimate_id);
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM estimates
                    WHERE id = target_estimate_id
                        AND current_version_id IS NOT NULL
                        AND status = 'approved'
                ) THEN
                    RAISE EXCEPTION 'approved_estimate_structure_is_immutable' USING ERRCODE = '23514';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql
            SQL);

        foreach (['estimate_sections', 'estimate_items', 'estimate_item_resources'] as $table) {
            DB::statement(sprintf(
                'CREATE TRIGGER %s_sealed_guard BEFORE INSERT OR UPDATE OR DELETE ON %s FOR EACH ROW EXECUTE FUNCTION guard_sealed_estimate_child()',
                $table,
                $table
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['estimate_sections', 'estimate_items', 'estimate_item_resources'] as $table) {
                DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_sealed_guard ON %s', $table, $table));
            }
            DB::statement('DROP FUNCTION IF EXISTS guard_sealed_estimate_child()');
            DB::statement('DROP TRIGGER IF EXISTS estimates_sealed_header_guard ON estimates');
            DB::statement('DROP FUNCTION IF EXISTS guard_sealed_estimate_header()');
            DB::statement('ALTER TABLE estimate_versions DROP CONSTRAINT IF EXISTS estimate_versions_status_check');
            DB::statement('DROP INDEX IF EXISTS estimate_versions_one_approved_idx');
        }

        Schema::table('estimates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_version_id');
        });

        Schema::table('estimate_versions', function (Blueprint $table): void {
            $table->dropUnique('estimate_versions_idempotency_unique');
            $table->dropIndex('estimate_versions_status_idx');
            $table->dropColumn(['status', 'idempotency_key', 'superseded_at']);
        });
    }
};
