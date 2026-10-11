<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $present = DB::selectOne("SELECT to_regclass('public.journal_entry_attachments') IS NOT NULL AND to_regprocedure('public.".AssistantStatusSnapshotEpoch::FUNCTION_NAME."()') IS NOT NULL AS present");
        if ($present === null || $present->present !== true) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON public.journal_entry_attachments');
            DB::statement('CREATE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON public.journal_entry_attachments FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
            DB::statement('ALTER TABLE public.journal_entry_attachments ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
        });
    }

    public function down(): void
    {
        if (DB::selectOne("SELECT to_regclass('public.journal_entry_attachments') IS NOT NULL AS present")?->present === true) {
            DB::transaction(function (): void {
                $state = DB::selectOne('SELECT EXISTS (SELECT 1 FROM pg_trigger WHERE tgrelid = to_regclass(?) AND tgname = ? AND NOT tgisinternal) AS tracked, to_regclass(?) IS NOT NULL AS epoch_present', ['public.journal_entry_attachments', AssistantStatusSnapshotEpoch::TRIGGER_NAME, 'public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE]);
                if ($state?->tracked === true && $state->epoch_present === true) {
                    DB::statement('UPDATE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' SET gc_generation = gc_generation + 1 WHERE id = 1');
                }
                DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' ON public.journal_entry_attachments');
            });
        }
    }
};
