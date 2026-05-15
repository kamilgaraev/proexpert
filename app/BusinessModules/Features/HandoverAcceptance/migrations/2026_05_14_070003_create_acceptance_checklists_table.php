<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_checklists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->string('title');
            $table->string('status', 40)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'project_id', 'acceptance_scope_id']);
        });

        Schema::create('acceptance_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('acceptance_checklist_id')->constrained('acceptance_checklists')->cascadeOnDelete();
            $table->string('title');
            $table->boolean('is_required')->default(true);
            $table->string('status', 40)->default('pending');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_checklist_items');
        Schema::dropIfExists('acceptance_checklists');
    }
};
