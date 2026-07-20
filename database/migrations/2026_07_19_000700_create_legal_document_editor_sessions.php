<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legal_document_editor_sessions')) {
            Schema::create('legal_document_editor_sessions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('source_version_id');
                $table->unsignedBigInteger('document_file_id');
                $table->unsignedBigInteger('opened_by_user_id');
                $table->string('provider', 40);
                $table->string('mode', 16);
                $table->string('status', 24);
                $table->unsignedInteger('generation');
                $table->string('document_key', 191);
                $table->char('source_content_hash', 64);
                $table->char('callback_replay_hash', 64)->nullable();
                $table->char('callback_lease_token_hash', 64)->nullable();
                $table->timestampTz('callback_lease_expires_at')->nullable();
                $table->unsignedInteger('callback_attempt_count')->default(0);
                $table->unsignedBigInteger('saved_version_id')->nullable();
                $table->timestampTz('expires_at');
                $table->timestampTz('completed_at')->nullable();
                $table->string('failure_code', 120)->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_editor_sessions_forward_only');
    }
};
