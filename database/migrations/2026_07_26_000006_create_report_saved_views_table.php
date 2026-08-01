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
        Schema::create('report_saved_views', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->string('report_code', 64);
            $table->string('contract_version', 32);
            $table->string('name', 120);
            $table->string('visibility', 24)->default('private');
            $table->jsonb('filters_json');
            $table->jsonb('comparison_json')->default('{}');
            $table->jsonb('sort_json');
            $table->jsonb('columns_json');
            $table->string('status', 32)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['organization_id', 'owner_id', 'report_code', 'created_at', 'id'], 'report_saved_views_owner_cursor_index');
        });
        DB::statement('CREATE UNIQUE INDEX report_saved_views_default_unique ON report_saved_views (organization_id, owner_id, report_code) WHERE is_default = true AND deleted_at IS NULL');
        DB::statement("ALTER TABLE report_saved_views ADD CONSTRAINT report_saved_views_status_check CHECK (status IN ('active','needs_migration'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('report_saved_views');
    }
};
