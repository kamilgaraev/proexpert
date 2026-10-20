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
            foreach (['estimate_generation_factory_runs', 'estimate_generation_factory_checkpoints'] as $table) {
                $relation = 'public.'.$table;
                $state = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS present, EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(?) AND tgname = ?) AS tracked', [$relation, $relation, AssistantStatusSnapshotEpoch::TRIGGER_NAME]);
                if ($state?->present !== true || $state->tracked === true) {
                    continue;
                }

                DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON '.$relation.' FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
                DB::statement('ALTER TABLE '.$relation.' ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
            }
        });
    }

    public function down(): void {}
};
