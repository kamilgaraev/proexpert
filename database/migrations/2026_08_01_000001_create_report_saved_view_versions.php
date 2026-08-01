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
        Schema::create('report_saved_view_versions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('saved_view_id');
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedInteger('revision');
            $table->string('report_code', 64);
            $table->string('contract_version', 32);
            $table->jsonb('content_json');
            $table->char('content_hash', 64);
            $table->char('report_definition_hash', 64);
            $table->timestampTz('created_at');

            $table->foreign('saved_view_id')
                ->references('id')
                ->on('report_saved_views')
                ->restrictOnDelete();
            $table->unique(
                ['saved_view_id', 'revision'],
                'report_saved_view_versions_revision_unique',
            );
            $table->unique(
                ['saved_view_id', 'content_hash'],
                'report_saved_view_versions_content_unique',
            );
            $table->index(
                ['organization_id', 'saved_view_id', 'revision'],
                'report_saved_view_versions_scope_revision_index',
            );
        });

        DB::statement('ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_revision_check CHECK (revision >= 1)');
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_content_hash_check CHECK (content_hash ~ '^[a-f0-9]{64}$')");
        DB::statement("ALTER TABLE report_saved_view_versions ADD CONSTRAINT report_saved_view_versions_definition_hash_check CHECK (report_definition_hash ~ '^[a-f0-9]{64}$')");

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->unsignedInteger('current_revision')->nullable();
            $table->index(
                ['id', 'current_revision'],
                'report_saved_views_current_revision_index',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE report_saved_views
                ADD CONSTRAINT report_saved_views_current_revision_foreign
                FOREIGN KEY (id, current_revision)
                REFERENCES report_saved_view_versions (saved_view_id, revision)
                ON DELETE RESTRICT
                DEFERRABLE INITIALLY DEFERRED
            SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE report_saved_views DROP CONSTRAINT IF EXISTS report_saved_views_current_revision_foreign',
        );

        Schema::table('report_saved_views', function (Blueprint $table): void {
            $table->dropIndex('report_saved_views_current_revision_index');
            $table->dropColumn('current_revision');
        });

        Schema::dropIfExists('report_saved_view_versions');
    }
};
