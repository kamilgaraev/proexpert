<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $present = DB::selectOne("SELECT to_regclass('public.estimate_generation_evaluation_revisions') IS NOT NULL AND to_regprocedure('public.".AssistantStatusSnapshotEpoch::FUNCTION_NAME."()') IS NOT NULL AS present");
        if ($present === null || $present->present !== true) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON public.estimate_generation_evaluation_revisions');
            DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON public.estimate_generation_evaluation_revisions FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
            DB::statement('ALTER TABLE public.estimate_generation_evaluation_revisions ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
        });
    }

    public function down(): void
    {
        if (DB::selectOne("SELECT to_regclass('public.estimate_generation_evaluation_revisions') IS NOT NULL AS present")?->present === true) {
            DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON public.estimate_generation_evaluation_revisions');
        }
    }
};
