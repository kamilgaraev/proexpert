<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('CREATE TABLE public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE.' (xid xid8 NOT NULL, relation_oid oid NOT NULL, created_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY (xid, relation_oid))');
            DB::statement('CREATE INDEX assistant_status_snapshot_changes_relation_idx ON public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE.' (relation_oid, xid)');
            DB::statement('CREATE INDEX assistant_status_snapshot_changes_created_idx ON public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE.' (created_at)');
            DB::statement('CREATE TABLE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' (id smallint PRIMARY KEY CHECK (id = 1), gc_generation bigint NOT NULL DEFAULT 0 CHECK (gc_generation >= 0))');
            DB::statement('INSERT INTO public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' (id) VALUES (1)');
            DB::unprepared('CREATE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $epoch$'.AssistantStatusSnapshotEpoch::FUNCTION_BODY.'$epoch$');
            $excluded = AssistantStatusSnapshotEpoch::EXCLUDED_TABLES;
            $tables = DB::select("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') ORDER BY c.oid");
            foreach ($tables as $table) {
                if (in_array($table->relname, $excluded, true)) { continue; }
                $name = 'public."'.str_replace('"', '""', $table->relname).'"';
                DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON '.$name.' FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
                DB::statement('ALTER TABLE '.$name.' ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $tables = DB::select("SELECT c.relname FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND t.tgname = ? AND t.tgfoid = to_regprocedure(?)", [AssistantStatusSnapshotEpoch::TRIGGER_NAME, 'public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()']);
            foreach ($tables as $table) {
                $name = 'public."'.str_replace('"', '""', $table->relname).'"';
                DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON '.$name);
            }
            DB::statement('DROP FUNCTION IF EXISTS public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
            DB::statement('DROP TABLE IF EXISTS public.'.AssistantStatusSnapshotEpoch::CHANGE_TABLE);
            DB::statement('DROP TABLE IF EXISTS public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE);
        });
    }
};
