<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_scope_work_quantities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->foreignId('completed_work_id')->constrained('completed_works')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('measurement_units')->restrictOnDelete();
            $table->decimal('presented_quantity', 24, 6);
            $table->decimal('accepted_quantity', 24, 6);
            $table->decimal('defect_quantity', 24, 6);
            $table->text('defect_reason')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 160)->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['acceptance_scope_id', 'completed_work_id']);
            $table->index(['organization_id', 'project_id', 'completed_work_id']);
            $table->index(['acceptance_scope_id', 'idempotency_key']);
        });
        DB::statement('ALTER TABLE acceptance_scope_work_quantities ADD CONSTRAINT acceptance_quantity_balance CHECK (presented_quantity >= 0 AND accepted_quantity >= 0 AND defect_quantity >= 0 AND presented_quantity = accepted_quantity + defect_quantity)');
        DB::statement("ALTER TABLE acceptance_scope_work_quantities ADD CONSTRAINT acceptance_defect_reason CHECK (defect_quantity = 0 OR (defect_reason IS NOT NULL AND length(trim(defect_reason)) > 0))");
        Schema::create('acceptance_scope_work_quantity_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->string('operation_key', 160);
            $table->string('payload_hash', 64);
            $table->unsignedInteger('expected_revision');
            $table->unsignedInteger('resulting_revision');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('result_snapshot');
            $table->timestamps();
            $table->unique(['acceptance_scope_id', 'operation_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_scope_work_quantity_operations');
        Schema::dropIfExists('acceptance_scope_work_quantities');
    }
};
