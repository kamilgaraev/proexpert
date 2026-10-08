<?php

declare(strict_types=1);

use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusScopedMutationGuard;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSourceMutationGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $tables = array_map(static fn (string $table): string => 'public.'.AssistantStatusSourceMutationGuard::quoteColumn($table), AssistantStatusScopedMutationGuard::TABLES);
            DB::statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('CREATE TABLE public.'.AssistantStatusScopedMutationGuard::CHANGE_TABLE.' (xid xid8 NOT NULL, relation_oid oid NOT NULL, organization_id bigint NOT NULL CHECK (organization_id >= 0), created_at timestamp with time zone NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY (xid, relation_oid, organization_id))');
            DB::statement('CREATE INDEX assistant_snapshot_scoped_changes_scope_idx ON public.'.AssistantStatusScopedMutationGuard::CHANGE_TABLE.' (relation_oid, organization_id, xid)');
            DB::statement('CREATE INDEX assistant_snapshot_scoped_changes_created_idx ON public.'.AssistantStatusScopedMutationGuard::CHANGE_TABLE.' (created_at)');
            DB::statement('ALTER TABLE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' ADD COLUMN scoped_guard_body text NULL');
            $connection = DB::connection();
            $bodies = [AssistantStatusScopedMutationGuard::ROW_FUNCTION => AssistantStatusScopedMutationGuard::rowBody(AssistantStatusScopedMutationGuard::sourceColumns($connection)),
                AssistantStatusScopedMutationGuard::TRUNCATE_FUNCTION => AssistantStatusScopedMutationGuard::TRUNCATE_BODY];
            foreach ($bodies as $function => $body) {
                $quoted = $connection->getPdo()->quote($body);
                if ($quoted === false) { throw new RuntimeException('assistant_snapshot_scoped_body_unavailable'); }
                DB::unprepared('CREATE FUNCTION public.'.$function.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.$quoted);
            }
            DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' SET scoped_guard_body = ? WHERE id = 1', [$bodies[AssistantStatusScopedMutationGuard::ROW_FUNCTION]]);
            DB::unprepared('CREATE FUNCTION public.'.AssistantStatusScopedMutationGuard::WITNESS_FUNCTION.'(target_relation oid, operation text) RETURNS void LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.$connection->getPdo()->quote(AssistantStatusScopedMutationGuard::WITNESS_BODY));
            DB::statement('REVOKE ALL ON FUNCTION public.'.AssistantStatusScopedMutationGuard::WITNESS_FUNCTION.'(oid,text) FROM PUBLIC');
            $this->replaceFunction(AssistantStatusSnapshotEpoch::FUNCTION_NAME, AssistantStatusSnapshotEpoch::FUNCTION_BODY);
            $this->replaceFunction(AssistantStatusSourceMutationGuard::ROW_FUNCTION, AssistantStatusSourceMutationGuard::body(AssistantStatusScopedMutationGuard::sourceColumns($connection)));
            foreach ($tables as $table) {
                DB::statement('CREATE TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER.' AFTER INSERT OR UPDATE OR DELETE ON '.$table.' FOR EACH ROW EXECUTE FUNCTION public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'()');
                DB::statement('ALTER TABLE '.$table.' ENABLE ALWAYS TRIGGER '.AssistantStatusScopedMutationGuard::ROW_TRIGGER);
                DB::statement('CREATE TRIGGER '.AssistantStatusScopedMutationGuard::TRUNCATE_TRIGGER.' AFTER TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION public.'.AssistantStatusScopedMutationGuard::TRUNCATE_FUNCTION.'()');
                DB::statement('ALTER TABLE '.$table.' ENABLE ALWAYS TRIGGER '.AssistantStatusScopedMutationGuard::TRUNCATE_TRIGGER);
            }
            if (AssistantStatusScopedMutationGuard::proof($connection)->cacheable !== true) {
                throw new RuntimeException('assistant_snapshot_scoped_installation_unproven');
            }
            $this->advanceGeneration();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (AssistantStatusScopedMutationGuard::TABLES as $table) {
                $name = 'public.'.AssistantStatusSourceMutationGuard::quoteColumn($table);
                DB::statement('LOCK TABLE '.$name.' IN SHARE ROW EXCLUSIVE MODE');
                DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusScopedMutationGuard::ROW_TRIGGER.' ON '.$name);
                DB::statement('DROP TRIGGER IF EXISTS '.AssistantStatusScopedMutationGuard::TRUNCATE_TRIGGER.' ON '.$name);
            }
            DB::statement('DROP FUNCTION IF EXISTS public.'.AssistantStatusScopedMutationGuard::ROW_FUNCTION.'()');
            DB::statement('DROP FUNCTION IF EXISTS public.'.AssistantStatusScopedMutationGuard::TRUNCATE_FUNCTION.'()');
            DB::statement('DROP FUNCTION IF EXISTS public.'.AssistantStatusScopedMutationGuard::WITNESS_FUNCTION.'(oid,text)');
            DB::statement('DROP TABLE IF EXISTS public.'.AssistantStatusScopedMutationGuard::CHANGE_TABLE);
            DB::statement('ALTER TABLE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' DROP COLUMN scoped_guard_body');
            $this->replaceFunction(AssistantStatusSnapshotEpoch::FUNCTION_NAME, AssistantStatusSnapshotEpoch::LEGACY_FUNCTION_BODY);
            $this->replaceFunction(AssistantStatusSourceMutationGuard::ROW_FUNCTION, AssistantStatusSourceMutationGuard::body(AssistantStatusScopedMutationGuard::sourceColumns(DB::connection()), legacy: true));
            $this->advanceGeneration();
        });
    }

    private function advanceGeneration(): void
    {
        if (DB::update('UPDATE public.'.AssistantStatusSnapshotEpoch::CONTROL_TABLE.' SET gc_generation = gc_generation + 1 WHERE id = 1') !== 1) {
            throw new RuntimeException('assistant_snapshot_control_missing');
        }
    }

    private function replaceFunction(string $function, string $body): void
    {
        $quoted = DB::connection()->getPdo()->quote($body);
        if ($quoted === false) { throw new RuntimeException('assistant_snapshot_scoped_body_unavailable'); }
        DB::unprepared('CREATE OR REPLACE FUNCTION public.'.$function.'() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS '.$quoted);
    }
};
