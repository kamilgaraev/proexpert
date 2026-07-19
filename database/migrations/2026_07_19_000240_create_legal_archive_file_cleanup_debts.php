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
        if (Schema::hasTable('legal_archive_file_cleanup_debts')) {
            return;
        }

        Schema::create('legal_archive_file_cleanup_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->text('storage_path');
            $table->string('reason', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['resolved_at', 'next_attempt_at'], 'legal_archive_cleanup_debts_pending_idx');
            $table->unique(['organization_id', 'storage_path'], 'legal_archive_cleanup_debts_object_unique');
        });
    }

    public function down(): void
    {
        if (
            Schema::hasTable('legal_archive_file_cleanup_debts')
            && DB::table('legal_archive_file_cleanup_debts')->whereNull('resolved_at')->exists()
        ) {
            throw new RuntimeException('legal_archive_cleanup_debts_rollback_blocked');
        }

        Schema::dropIfExists('legal_archive_file_cleanup_debts');
    }
};
