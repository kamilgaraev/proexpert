<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('executive_document_versions', function (Blueprint $table): void {
            $table->string('status', 32)->default('draft')->after('version_number');
            $table->string('content_hash', 64)->nullable()->after('file_url');
            $table->jsonb('profile_snapshot')->nullable()->after('metadata');
            $table->jsonb('basis_snapshot')->nullable()->after('profile_snapshot');
            $table->string('operation_key', 128)->nullable()->after('basis_snapshot');
            $table->string('operation_hash', 64)->nullable()->after('operation_key');
            $table->foreignId('approved_by')->nullable()->after('uploaded_by')->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('uploaded_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->timestamp('transmitted_at')->nullable()->after('approved_at');
            $table->unique(['organization_id', 'document_id', 'operation_key'], 'executive_document_versions_operation_unique');
            $table->index(['document_id', 'status']);
        });
        Schema::table('executive_document_remarks', function (Blueprint $table): void {
            $table->foreignId('version_id')->nullable()->after('document_id')->constrained('executive_document_versions')->nullOnDelete();
            $table->text('response')->nullable()->after('resolution_comment');
            $table->jsonb('metadata')->nullable()->after('response');
            $table->index(['document_id', 'version_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('executive_document_remarks', function (Blueprint $table): void {
            $table->dropIndex(['document_id', 'version_id', 'status']);
            $table->dropForeign(['version_id']);
            $table->dropColumn(['version_id', 'response', 'metadata']);
        });
        Schema::table('executive_document_versions', function (Blueprint $table): void {
            $table->dropUnique('executive_document_versions_operation_unique');
            $table->dropIndex(['document_id', 'status']);
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['status', 'content_hash', 'profile_snapshot', 'basis_snapshot', 'operation_key', 'operation_hash', 'approved_by', 'submitted_at', 'approved_at', 'transmitted_at']);
        });
    }
};
