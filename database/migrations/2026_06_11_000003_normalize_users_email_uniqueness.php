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

        $hasDuplicates = DB::table('users')
            ->selectRaw('lower(trim(email)) as normalized_email')
            ->whereNotNull('email')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->exists();

        if ($hasDuplicates) {
            throw new RuntimeException('Cannot normalize users.email because case-insensitive duplicates exist.');
        }

        DB::statement("UPDATE users SET email = lower(trim(email)) WHERE email IS NOT NULL AND email <> lower(trim(email))");
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users ((lower(email)))');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
    }
};
