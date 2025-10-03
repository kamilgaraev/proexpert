<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_reports')) {
            Schema::create('custom_reports', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->text('description')->nullable();
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->string('report_category')->index();
                $table->json('data_sources');
                $table->json('query_config')->nullable();
                $table->json('columns_config');
                $table->json('filters_config')->nullable();
                $table->json('aggregations_config')->nullable();
                $table->json('sorting_config')->nullable();
                $table->json('visualization_config')->nullable();
                $table->boolean('is_shared')->default(false);
                $table->boolean('is_favorite')->default(false);
                $table->boolean('is_scheduled')->default(false);
                $table->integer('execution_count')->default(0);
                $table->timestamp('last_executed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['organization_id', 'is_shared', 'deleted_at'], 'custom_reports_org_shared_deleted_idx');
                $table->index(['user_id', 'deleted_at'], 'custom_reports_user_deleted_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_reports');
    }
};

