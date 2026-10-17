<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT to_regprocedure(?) IS NOT NULL AS present', ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()'])?->present !== true) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");
            $tables = DB::select("SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
                AND NOT EXISTS (SELECT 1 FROM pg_trigger t WHERE t.tgrelid = c.oid AND t.tgname = ?) ORDER BY c.oid", [AssistantStatusSnapshotEpoch::TRIGGER_NAME]);
            foreach ($tables as $table) {
                if (in_array($table->relname, AssistantStatusSnapshotEpoch::EXCLUDED_TABLES, true)) {
                    continue;
                }
                $name = 'public."'.str_replace('"', '""', $table->relname).'"';
                DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON '.$name.' FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
                DB::statement('ALTER TABLE '.$name.' ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            }
        });
    }

    public function down(): void {}
};
