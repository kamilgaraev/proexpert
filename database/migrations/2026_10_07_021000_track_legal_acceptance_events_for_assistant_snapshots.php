<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $ready = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_class WHERE oid=to_regclass('public.legal_acceptance_events') AND relkind IN ('r','p'))
            AND to_regprocedure(?) IS NOT NULL AS ready", ['public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()']);
        if ($ready->ready !== true) {
            return;
        }

        DB::statement('CREATE OR REPLACE TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME.' AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE
            ON public.legal_acceptance_events FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusSnapshotEpoch::FUNCTION_NAME.'()');
        DB::statement('ALTER TABLE public.legal_acceptance_events ENABLE ALWAYS TRIGGER '.AssistantStatusSnapshotEpoch::TRIGGER_NAME);
    }

    public function down(): void {}
};
