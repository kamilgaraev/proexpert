<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acceptance_signoffs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acceptance_scope_id')->constrained('acceptance_scopes')->cascadeOnDelete();
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40);
            $table->text('comment')->nullable();
            $table->timestamp('signed_at');
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'project_id', 'acceptance_scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acceptance_signoffs');
    }
};
