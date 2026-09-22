<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_work_history_transformations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('completed_work_id')->constrained('completed_works')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);
            $table->string('rule', 64);
            $table->string('outcome', 32);
            $table->boolean('fields_mutated')->default(false);
            $table->string('operation_key', 128);
            $table->string('payload_hash', 64);
            $table->text('reason')->nullable();
            $table->decimal('original_quantity', 18, 4)->nullable();
            $table->decimal('original_completed_quantity', 18, 4)->nullable();
            $table->decimal('canonical_quantity', 18, 4)->nullable();
            $table->jsonb('protocol');
            $table->timestamps();
            $table->unique('completed_work_id');
            $table->unique(['completed_work_id', 'operation_key']);
            $table->index(['organization_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_work_history_transformations');
    }
};
