<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('failure_identity_scope_migration_requires_postgresql');
        }

        DB::transaction(static function (): void {
            DB::statement("SET LOCAL lock_timeout TO '5s'");
            DB::statement("SET LOCAL statement_timeout TO '120s'");
            DB::statement('LOCK TABLE public.estimate_generation_failure_identities IN SHARE ROW EXCLUSIVE MODE');
            DB::statement('ALTER TABLE public.estimate_generation_failure_identities DROP CONSTRAINT eg_failure_identities_fingerprint_uq');
            DB::statement('CREATE INDEX eg_failure_identities_fingerprint_idx ON public.estimate_generation_failure_identities (fingerprint)');
            DB::statement('ALTER TABLE public.estimate_generation_failure_events DROP CONSTRAINT eg_failure_events_safe_context_privacy_ck');
            DB::statement(<<<'SQL'
                ALTER TABLE public.estimate_generation_failure_events
                ADD CONSTRAINT eg_failure_events_safe_context_privacy_ck CHECK (
                    NOT jsonb_path_exists(
                        safe_context,
                        '$.* ? (@.type() == "string" && @ like_regex "(prompt|request|response|content|filename|file_name|path|authorization|api_key|apikey|token|secret|password|cookie|bearer|eyj[a-z0-9_-]{8,}\\.|akia[0-9a-z]{12,}|gh[pousr]_[0-9a-z]{12,}|sk-[0-9a-z]{8,})" flag "i")'
                    )
                )
                SQL);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('failure_identity_scope_contract_is_irreversible');
    }
};
