<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_sessions_created_at_idx ON estimate_generation_sessions (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_sessions_status_created_at_idx ON estimate_generation_sessions (status, created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS eg_documents_session_mime_idx ON estimate_generation_documents (session_id, mime_type)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sessions_created_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sessions_status_created_at_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_documents_session_mime_idx');
    }
};
