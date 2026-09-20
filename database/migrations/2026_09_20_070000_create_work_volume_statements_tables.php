<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_volume_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->uuid('statement_key');
            $table->unsignedInteger('version');
            $table->foreignId('based_on_statement_id')->nullable()->constrained('work_volume_statements')->restrictOnDelete();
            $table->string('operation_key', 128)->nullable();
            $table->string('operation_hash', 64)->nullable();
            $table->string('name');
            $table->string('status', 24)->default('draft');
            $table->text('change_reason')->nullable();
            $table->string('basis_revision')->nullable();
            $table->string('source_file_path')->nullable();
            $table->string('source_file_hash', 128)->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->unsignedInteger('review_round')->default(0);
            $table->jsonb('review_history')->default('[]');
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'project_id', 'statement_key', 'version'], 'wvs_scope_key_version');
            $table->unique(['organization_id', 'project_id', 'operation_key'], 'wvs_scope_operation_key');
            $table->index(['organization_id', 'project_id', 'status'], 'wvs_scope_status');
        });

        Schema::create('work_volume_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('statement_id')->constrained('work_volume_statements')->cascadeOnDelete();
            $table->uuid('line_key');
            $table->string('name');
            $table->string('unit_code', 32);
            $table->decimal('quantity', 24, 6);
            $table->jsonb('place');
            $table->text('measurement_formula')->nullable();
            $table->string('basis_revision')->nullable();
            $table->foreignId('estimate_item_id')->nullable()->constrained('estimate_items')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['statement_id', 'line_key'], 'wvsl_statement_line_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_volume_statement_lines');
        Schema::dropIfExists('work_volume_statements');
    }
};
