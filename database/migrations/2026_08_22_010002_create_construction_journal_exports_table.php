<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('construction_journal_exports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_id')->constrained('construction_journals')->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('construction_journal_entries')->nullOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('format', 8);
            $table->jsonb('options');
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('status', 16)->default('queued');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('result_path')->nullable();
            $table->string('error_code')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['organization_id', 'requested_by_user_id', 'idempotency_key'],
                'journal_exports_idempotency_unique'
            );
            $table->index(['journal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('construction_journal_exports');
    }
};
