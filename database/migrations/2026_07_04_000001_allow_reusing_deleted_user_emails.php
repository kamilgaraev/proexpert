<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $hasActiveDuplicates = DB::table('users')
            ->selectRaw('lower(trim(email)) as normalized_email')
            ->whereNotNull('email')
            ->whereNull('deleted_at')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasActiveDuplicates) {
            throw new RuntimeException('Cannot change users.email uniqueness because active case-insensitive duplicates exist.');
        }

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_unique');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_active_unique ON users ((lower(email))) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_active_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users ((lower(email)))');
        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'users_email_unique'
    ) THEN
        ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email);
    END IF;
END
$$;
SQL);
    }
};
