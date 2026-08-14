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
        });
    }

    public function down(): void
    {
        throw new RuntimeException('failure_identity_scope_contract_is_irreversible');
    }
};
