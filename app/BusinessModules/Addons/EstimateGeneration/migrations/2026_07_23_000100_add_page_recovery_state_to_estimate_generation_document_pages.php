<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
            if (! Schema::hasColumn('estimate_generation_document_pages', 'status')) {
                $table->string('status', 24)->default('ready')->after('quality_flags');
            }
            if (! Schema::hasColumn('estimate_generation_document_pages', 'excluded_at')) {
                $table->timestampTz('excluded_at')->nullable()->after('status');
            }
            if (! Schema::hasColumn('estimate_generation_document_pages', 'excluded_reason')) {
                $table->string('excluded_reason', 500)->nullable()->after('excluded_at');
            }
            if (! Schema::hasColumn('estimate_generation_document_pages', 'retry_attempt_id')) {
                $table->string('retry_attempt_id', 36)->nullable()->after('excluded_reason');
            }
            if (! Schema::hasColumn('estimate_generation_document_pages', 'last_retry_requested_at')) {
                $table->timestampTz('last_retry_requested_at')->nullable()->after('retry_attempt_id');
            }
        });

        if (! $this->indexExists('estimate_generation_document_pages', 'eg_pages_scope_status_idx')) {
            Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
                $table->index(
                    ['organization_id', 'project_id', 'session_id', 'document_id', 'status'],
                    'eg_pages_scope_status_idx',
                );
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_document_pages DROP CONSTRAINT IF EXISTS eg_pages_status_ck");
            DB::statement("ALTER TABLE estimate_generation_document_pages ADD CONSTRAINT eg_pages_status_ck CHECK (status IN ('ready','needs_review','queued','processing','failed','excluded'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE estimate_generation_document_pages DROP CONSTRAINT IF EXISTS eg_pages_status_ck');
        }

        if ($this->indexExists('estimate_generation_document_pages', 'eg_pages_scope_status_idx')) {
            Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
                $table->dropIndex('eg_pages_scope_status_idx');
            });
        }

        Schema::table('estimate_generation_document_pages', function (Blueprint $table): void {
            foreach (['last_retry_requested_at', 'retry_attempt_id', 'excluded_reason', 'excluded_at', 'status'] as $column) {
                if (Schema::hasColumn('estimate_generation_document_pages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        return DB::table('pg_indexes')
            ->where('schemaname', 'public')
            ->where('tablename', $table)
            ->where('indexname', $index)
            ->exists();
    }
};
