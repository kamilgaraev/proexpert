<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\Contracts\Database\ForwardOnlyMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration implements ForwardOnlyMigration
{
    public function up(): void
    {
        if (DB::selectOne('SELECT to_regprocedure(?) IS NOT NULL AS present', ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()'])?->present !== true) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement("SET LOCAL statement_timeout = '30s'");
            foreach (['project_event_series', 'project_event_series_segments', 'project_event_series_operations'] as $table) {
                $relation = 'public.'.$table;
                $state = DB::selectOne(<<<'SQL'
                    SELECT to_regclass(?) IS NOT NULL AS present, EXISTS (
                        SELECT 1 FROM pg_catalog.pg_trigger t
                        WHERE t.tgrelid = to_regclass(?) AND t.tgname = ? AND t.tgfoid = to_regprocedure(?)
                            AND NOT t.tgisinternal AND t.tgtype = 60 AND t.tgenabled = 'A'
                            AND t.tgconstraint = 0 AND t.tgnargs = 0 AND t.tgqual IS NULL AND t.tgattr::text = ''
                    ) AS valid
                    SQL, [$relation, $relation, AssistantStatusSnapshotEpoch::TRIGGER_NAME, 'public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()']);
                if ($state?->present !== true || $state->valid === true) {
                    continue;
                }

                DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON '.$relation);
                DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON '.$relation.' FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
                DB::statement('ALTER TABLE '.$relation.' ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            }
        });
    }

    public function down(): void {}
};
