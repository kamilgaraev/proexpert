<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_report_schedules')) {
            Schema::create('custom_report_schedules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('custom_report_id')->constrained('custom_reports')->onDelete('cascade');
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->enum('schedule_type', ['daily', 'weekly', 'monthly', 'custom_cron'])->default('daily');
                $table->json('schedule_config');
                $table->json('filters_preset')->nullable();
                $table->json('recipient_emails');
                $table->enum('export_format', ['csv', 'excel', 'pdf'])->default('excel');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_run_at')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->foreignId('last_execution_id')->nullable()->constrained('custom_report_executions')->onDelete('set null');
                $table->timestamps();

                $table->index(['custom_report_id', 'is_active'], 'custom_schedules_report_active_idx');
                $table->index(['next_run_at', 'is_active'], 'custom_schedules_next_active_idx');
                $table->index('organization_id', 'custom_schedules_org_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_schedules');
    }
};

