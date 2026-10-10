<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSourceMutationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE public.ai_rag_sources IN SHARE ROW EXCLUSIVE MODE');
            $table = DB::selectOne("SELECT c.relkind = 'r' AND NOT EXISTS (SELECT 1 FROM pg_inherits i WHERE i.inhrelid = c.oid OR i.inhparent = c.oid) AS ordinary FROM pg_class c WHERE c.oid = to_regclass('public.ai_rag_sources')");
            if ($table === null || $table->ordinary !== true) {
                throw new RuntimeException('assistant_source_snapshot_requires_ordinary_table');
            }
            $timestamps = DB::selectOne("SELECT COUNT(*) AS count FROM pg_attribute WHERE attrelid = to_regclass('public.ai_rag_sources') AND attnum > 0 AND NOT attisdropped AND attname IN ('last_reconciled_at', 'updated_at') AND atttypid IN ('timestamp'::regtype, 'timestamptz'::regtype) AND attgenerated = '' AND attidentity = ''");
            if ((int) $timestamps->count !== 2) {
                throw new RuntimeException('assistant_source_snapshot_timestamps_unproven');
            }
            $columns = array_map(static fn (object $column): string => (string) $column->attname, DB::select("SELECT attname FROM pg_attribute WHERE attrelid = to_regclass('public.ai_rag_sources') AND attnum > 0 AND NOT attisdropped AND attname NOT IN ('last_reconciled_at', 'updated_at') ORDER BY attnum"));
            if ($columns === []) {
                throw new RuntimeException('assistant_source_snapshot_columns_missing');
            }
            $old = implode(', ', array_map(static fn (string $column): string => 'source_record.'.AssistantStatusSourceMutationGuard::quoteColumn($column), $columns));
            DB::select('EXPLAIN SELECT ROW('.$old.') IS DISTINCT FROM ROW('.$old.') FROM public.ai_rag_sources source_record LIMIT 0');
            $body = DB::connection()->getPdo()->quote(AssistantStatusSourceMutationGuard::body($columns));
            if ($body === false) {
                throw new RuntimeException('assistant_source_snapshot_body_unavailable');
            }
            DB::unprepared('CREATE OR REPLACE FUNCTION public.'.AssistantStatusSourceMutationGuard::ROW_FUNCTION.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.$body);
            DB::statement('CREATE OR REPLACE TRIGGER '.AssistantStatusSourceMutationGuard::ROW_TRIGGER.' AFTER UPDATE ON public.ai_rag_sources FOR EACH ROW EXECUTE FUNCTION public.'.AssistantStatusSourceMutationGuard::ROW_FUNCTION.'()');
            DB::statement('ALTER TABLE public.ai_rag_sources ENABLE ALWAYS TRIGGER '.AssistantStatusSourceMutationGuard::ROW_TRIGGER);
            $list = implode(', ', array_map(AssistantStatusSourceMutationGuard::quoteColumn(...), $columns));
            DB::statement('CREATE OR REPLACE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR DELETE OR TRUNCATE OR UPDATE OF '.$list.' ON public.ai_rag_sources FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
            DB::statement('ALTER TABLE public.ai_rag_sources ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            if (DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' SET gc_generation = gc_generation + 1 WHERE id = 1') !== 1) {
                throw new RuntimeException('assistant_source_snapshot_control_missing');
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE public.ai_rag_sources IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('CREATE OR REPLACE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON public.ai_rag_sources FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
            DB::statement('ALTER TABLE public.ai_rag_sources ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSourceMutationGuard::ROW_TRIGGER.' ON public.ai_rag_sources');
            DB::statement('DROP FUNCTION IF EXISTS public.'.AssistantStatusSourceMutationGuard::ROW_FUNCTION.'()');
            if (DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' SET gc_generation = gc_generation + 1 WHERE id = 1') !== 1) {
                throw new RuntimeException('assistant_source_snapshot_control_missing');
            }
        });
    }
};
