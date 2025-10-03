<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('custom_report_executions')) {
            Schema::create('custom_report_executions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('custom_report_id')->constrained('custom_reports')->onDelete('cascade');
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
                $table->json('applied_filters')->nullable();
                $table->integer('execution_time_ms')->nullable();
                $table->integer('result_rows_count')->nullable();
                $table->string('export_format')->nullable();
                $table->string('export_file_id')->nullable();
                $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
                $table->text('error_message')->nullable();
                $table->text('query_sql')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();

                $table->index(['custom_report_id', 'created_at'], 'custom_executions_report_created_idx');
                $table->index(['user_id', 'created_at'], 'custom_executions_user_created_idx');
                $table->index(['status', 'created_at'], 'custom_executions_status_created_idx');
                $table->foreign('export_file_id')->references('id')->on('report_files')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_report_executions');
    }
};

